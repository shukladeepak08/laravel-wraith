<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Dynamic;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\DynamicAnalyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;

/**
 * Opt-in dynamic analysis: replays routes and inspects the query log.
 *
 * WARNING: Makes real HTTP kernel requests. Default is GET-only.
 * Side effects are possible if routes are not read-only.
 */
final class QueryPatternAnalyzer extends AbstractAnalyzer implements DynamicAnalyzer
{
    /** @var array<int, array{sql: string, bindings: array, time: float}> */
    private $queries = [];

    public function category(): string
    {
        return Category::DYNAMIC;
    }

    public function name(): string
    {
        return 'Dynamic Query Patterns';
    }

    public function supports(): bool
    {
        return true;
    }

    public function analyze(): AnalysisResult
    {
        $this->queries = [];
        $findings = [];

        $findings[] = $this->finding(
            Severity::INFO,
            $this->category(),
            'dynamic.side_effect_warning',
            'Dynamic mode makes real requests to your application.',
            'Non-read-only routes can mutate data. Defaults to GET-only; allow non-GET explicitly in config.',
            'Use --routes= to limit scope, and run against a disposable environment when possible.'
        );

        DB::listen(function ($query) {
            $this->queries[] = [
                'sql' => (string) $query->sql,
                'bindings' => is_array($query->bindings) ? $query->bindings : [],
                'time' => (float) $query->time,
            ];
        });

        $routes = $this->selectRoutes();
        $kernel = app()->make(\Illuminate\Contracts\Http\Kernel::class);

        foreach ($routes as $uri) {
            try {
                $request = Request::create('/'.ltrim($uri, '/'), 'GET');
                $kernel->handle($request);
            } catch (\Throwable $e) {
                // Continue — individual route failures should not abort the run.
            }
        }

        $findings = array_merge($findings, $this->detectDuplicates());
        $findings = array_merge($findings, $this->detectNPlusOne());
        $findings = array_merge($findings, $this->detectSlow());

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, string>
     */
    private function selectRoutes(): array
    {
        $patterns = (array) config('wraith.dynamic.route_patterns', ['*']);
        $max = (int) config('wraith.dynamic.max_routes', 50);
        $methods = (array) config('wraith.dynamic.methods', ['GET']);
        $allowNonGet = (bool) config('wraith.dynamic.allow_non_get', false);

        $selected = [];

        foreach (Route::getRoutes() as $route) {
            $routeMethods = method_exists($route, 'methods') ? $route->methods() : [];
            $uri = method_exists($route, 'uri') ? $route->uri() : '';

            if ($uri === '' || strpos($uri, '{') !== false) {
                continue;
            }

            $isGet = in_array('GET', $routeMethods, true);

            if (! $isGet && ! $allowNonGet) {
                continue;
            }

            if ($isGet && ! in_array('GET', $methods, true)) {
                continue;
            }

            if (! $this->matchesPatterns($uri, $patterns)) {
                continue;
            }

            $selected[] = $uri;

            if (count($selected) >= $max) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param array<int, string> $patterns
     */
    private function matchesPatterns(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '/^'.str_replace(['\*', '\/'], ['.*', '\/'], preg_quote($pattern, '/')).'$/';

            if (preg_match($regex, $uri) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function detectDuplicates(): array
    {
        $counts = [];

        foreach ($this->queries as $query) {
            $signature = $query['sql'].'|'.json_encode($query['bindings']);
            $counts[$signature] = isset($counts[$signature]) ? $counts[$signature] + 1 : 1;
        }

        $findings = [];

        foreach ($counts as $signature => $count) {
            if ($count < 3) {
                continue;
            }

            $sql = explode('|', (string) $signature, 2)[0];
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'dynamic.duplicate_query',
                sprintf('Duplicate query executed %d times: %s', $count, $this->truncate($sql)),
                'Identical queries waste database round-trips within a single request cycle.',
                'Cache the result in-request or refactor to avoid repeated lookups.',
                null,
                false,
                ['sql' => $sql, 'count' => $count]
            );
        }

        return array_slice($findings, 0, 15);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function detectNPlusOne(): array
    {
        $threshold = (int) config('wraith.dynamic.n_plus_one_threshold', 5);
        $shapes = [];

        foreach ($this->queries as $query) {
            $shape = preg_replace('/\s+/', ' ', $query['sql']);
            $shape = preg_replace('/\b\d+\b/', '?', (string) $shape);
            $shapes[$shape] = isset($shapes[$shape]) ? $shapes[$shape] + 1 : 1;
        }

        $findings = [];

        foreach ($shapes as $shape => $count) {
            if ($count < $threshold) {
                continue;
            }

            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'dynamic.n_plus_one',
                sprintf('Possible N+1: query shape ran %d times: %s', $count, $this->truncate((string) $shape)),
                'N+1 query patterns explode request latency as related records grow.',
                'Eager-load relationships with with()/load() or batch the lookups.',
                'https://laravel.com/docs/eloquent-relationships#eager-loading',
                false,
                ['shape' => $shape, 'count' => $count]
            );
        }

        return array_slice($findings, 0, 15);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function detectSlow(): array
    {
        $threshold = (float) config('wraith.dynamic.slow_query_ms', 100);
        $findings = [];

        foreach ($this->queries as $query) {
            if ($query['time'] < $threshold) {
                continue;
            }

            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'dynamic.slow_query',
                sprintf('Slow query (%.1f ms): %s', $query['time'], $this->truncate($query['sql'])),
                'Slow queries dominate request time under load.',
                'Add indexes, reduce selected columns, or rewrite the query.',
                null,
                false,
                ['sql' => $query['sql'], 'time_ms' => $query['time']]
            );
        }

        return array_slice($findings, 0, 15);
    }

    private function truncate(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?: $sql;

        if (strlen($sql) <= 120) {
            return $sql;
        }

        return substr($sql, 0, 117).'...';
    }
}
