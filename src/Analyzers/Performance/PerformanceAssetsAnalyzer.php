<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Performance;

use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;

final class PerformanceAssetsAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::PERFORMANCE;
    }

    public function name(): string
    {
        return 'Performance & Assets';
    }

    public function supports(): bool
    {
        return true;
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];
        $findings = array_merge($findings, $this->checkDrivers());
        $findings = array_merge($findings, $this->checkOpcache());
        $findings = array_merge($findings, $this->checkAssets());
        $findings = array_merge($findings, $this->checkHorizonOctane());

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkDrivers(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        $findings = [];

        $queue = (string) config('queue.default', 'sync');

        if ($queue === 'sync') {
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'performance.queue_sync',
                'Queue driver is `sync` in production.',
                'Jobs run inline during the HTTP request, hurting latency and reliability.',
                'Use redis, database, sqs, or another async queue driver.',
                'https://laravel.com/docs/queues'
            );
        }

        $cache = (string) config('cache.default', 'file');

        if (in_array($cache, ['file', 'array'], true)) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'performance.cache_driver',
                sprintf('Cache driver is `%s` in production.', $cache),
                'File/array cache does not scale across servers and is slower under load.',
                'Prefer redis or memcached for production caching.',
                'https://laravel.com/docs/cache'
            );
        }

        $session = (string) config('session.driver', 'file');

        if ($session === 'array') {
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'performance.session_array',
                'Session driver is `array` in production.',
                'Array sessions are request-local and will not persist user sessions.',
                'Use cookie, database, redis, or another persistent session driver.'
            );
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkOpcache(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        if (! function_exists('opcache_get_status')) {
            return [
                $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'performance.opcache_missing',
                    'OPcache extension is not available.',
                    'Without OPcache, PHP recompiles scripts on every request.',
                    'Enable the OPcache extension in production PHP builds.'
                ),
            ];
        }

        $status = @opcache_get_status(false);

        if ($status === false || empty($status['opcache_enabled'])) {
            return [
                $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'performance.opcache_disabled',
                    'OPcache is installed but not enabled.',
                    'Disabled OPcache wastes CPU on repeated compilation.',
                    'Set opcache.enable=1 in php.ini for production.'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkAssets(): array
    {
        $findings = [];
        $manifests = [
            public_path('build/manifest.json'),
            public_path('mix-manifest.json'),
        ];

        $foundManifest = null;

        foreach ($manifests as $manifest) {
            if (is_file($manifest)) {
                $foundManifest = $manifest;
                break;
            }
        }

        if ($foundManifest === null && $this->isProduction()) {
            $findings[] = $this->finding(
                Severity::LOW,
                $this->category(),
                'performance.assets_manifest_missing',
                'No Vite/Mix asset manifest found in public/.',
                'Missing built assets often means production is serving uncompiled frontend code.',
                'Run your frontend build (`npm run build`) during deploy.'
            );

            return $findings;
        }

        if ($foundManifest === null) {
            return [];
        }

        // Sample a built JS file for minification heuristic (presence of whitespace-heavy source maps aside).
        $dir = dirname($foundManifest);
        $jsFiles = glob($dir.'/**/*.js') ?: glob($dir.'/*.js') ?: [];

        foreach (array_slice($jsFiles, 0, 5) as $js) {
            $sample = (string) file_get_contents($js);
            $length = strlen($sample);

            if ($length > 5000) {
                $newlines = substr_count($sample, "\n");
                $ratio = $newlines / max(1, $length);

                if ($ratio > 0.02) {
                    $findings[] = $this->finding(
                        Severity::LOW,
                        $this->category(),
                        'performance.assets_not_minified',
                        sprintf('Built asset %s looks unminified.', basename($js)),
                        'Unminified assets increase transfer size and parse time.',
                        'Ensure production builds minify JS/CSS (Vite/Mix production mode).',
                        null,
                        false,
                        ['file' => $js]
                    );
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkHorizonOctane(): array
    {
        $findings = [];

        if (class_exists(\Laravel\Horizon\Horizon::class) && ! is_file(config_path('horizon.php'))) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'performance.horizon_config_missing',
                'Laravel Horizon is installed but config/horizon.php is missing.',
                'Horizon without published config often runs with unsuitable defaults.',
                'Run `php artisan horizon:install` and tune provisioning plans.'
            );
        }

        if (class_exists(\Laravel\Octane\Octane::class) && ! is_file(config_path('octane.php'))) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'performance.octane_config_missing',
                'Laravel Octane is installed but config/octane.php is missing.',
                'Octane needs explicit worker and sandbox configuration.',
                'Publish and review the Octane config before production use.'
            );
        }

        return $findings;
    }
}
