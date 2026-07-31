<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Routes;

use Illuminate\Support\Facades\Route;
use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;

final class RoutesAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::ROUTES;
    }

    public function name(): string
    {
        return 'Routes';
    }

    public function supports(): bool
    {
        return true;
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];
        $routes = Route::getRoutes();
        $seen = [];
        $unnamed = 0;
        $closures = 0;
        $apiWithoutThrottle = 0;
        $authWithoutThrottle = [];

        foreach ($routes as $route) {
            $methods = method_exists($route, 'methods') ? $route->methods() : [];
            $uri = method_exists($route, 'uri') ? $route->uri() : '';
            $key = implode('|', $methods).'@'.$uri;

            if (isset($seen[$key])) {
                $findings[] = $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'routes.duplicate',
                    sprintf('Duplicate route definition: %s %s', implode(',', $methods), $uri),
                    'Duplicate routes cause ambiguous matching and surprise overrides.',
                    'Remove or rename the duplicate route definition.',
                    null,
                    false,
                    ['uri' => $uri, 'methods' => $methods]
                );
            }

            $seen[$key] = true;

            $name = method_exists($route, 'getName') ? $route->getName() : null;

            if ($name === null || $name === '') {
                $action = method_exists($route, 'getActionName') ? $route->getActionName() : '';

                if (strpos((string) $action, 'Closure') === false && ! $this->isFrameworkRoute($uri)) {
                    $unnamed++;
                }
            }

            $action = method_exists($route, 'getAction') ? $route->getAction() : [];

            if (isset($action['uses']) && $action['uses'] instanceof \Closure) {
                $closures++;
            } elseif (isset($action['controller']) && is_object($action['controller']) && $action['controller'] instanceof \Closure) {
                $closures++;
            } elseif (method_exists($route, 'getActionName') && $route->getActionName() === 'Closure') {
                $closures++;
            }

            $middleware = [];

            if (method_exists($route, 'gatherMiddleware')) {
                $middleware = $route->gatherMiddleware();
            } elseif (method_exists($route, 'middleware')) {
                $middleware = $route->middleware();
            }

            $isApi = strpos($uri, 'api/') === 0 || in_array('api', $middleware, true);
            $hasThrottle = false;

            foreach ($middleware as $mw) {
                if (is_string($mw) && (strpos($mw, 'throttle') === 0 || strpos($mw, 'RateLimiter') !== false)) {
                    $hasThrottle = true;
                    break;
                }
            }

            if ($isApi && ! $hasThrottle && ! $this->isFrameworkRoute($uri)) {
                $apiWithoutThrottle++;
            }

            if ($this->isSensitiveAuthRoute($uri, $name) && ! $hasThrottle) {
                $authWithoutThrottle[] = $uri;
            }
        }

        if ($unnamed > 0) {
            $findings[] = $this->finding(
                Severity::LOW,
                $this->category(),
                'routes.unnamed',
                sprintf('%d route(s) have no name.', $unnamed),
                'Named routes make refactors safer and enable signed URL helpers.',
                'Add ->name(...) to important routes.'
            );
        }

        if ($closures > 0 && $this->isProduction()) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'routes.closure_in_production',
                sprintf('%d closure route(s) registered (cannot be route-cached).', $closures),
                'Closure routes prevent `route:cache`, hurting production boot performance.',
                'Move closures to controller actions before enabling route caching.',
                'https://laravel.com/docs/controllers'
            );
        }

        if ($apiWithoutThrottle > 0) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'routes.api_missing_rate_limiter',
                sprintf('%d API route(s) appear to lack a rate limiter.', $apiWithoutThrottle),
                'Unthrottled API endpoints are easier to abuse for brute-force and DoS.',
                'Apply the throttle middleware or RateLimiter to API routes.',
                'https://laravel.com/docs/routing#rate-limiting'
            );
        }

        if ($authWithoutThrottle !== []) {
            $sample = array_slice(array_values(array_unique($authWithoutThrottle)), 0, 8);
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'routes.auth_missing_rate_limiter',
                sprintf('Sensitive auth route(s) without throttle: %s', implode(', ', $sample)),
                'Login, password reset, and OTP endpoints are prime brute-force targets.',
                'Add throttle middleware (or RateLimiter) to login/password/OTP routes.',
                'https://laravel.com/docs/routing#rate-limiting',
                false,
                ['routes' => $sample]
            );
        }

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @param mixed $name
     */
    private function isSensitiveAuthRoute(string $uri, $name): bool
    {
        $haystack = strtolower($uri.' '.(string) $name);
        $needles = [
            'login',
            'password/email',
            'password/reset',
            'forgot-password',
            'reset-password',
            'two-factor',
            '2fa',
            'otp',
            'register',
        ];

        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isFrameworkRoute(string $uri): bool
    {
        return $uri === '/'
            || strpos($uri, '_ignition') !== false
            || strpos($uri, 'sanctum/') !== false
            || strpos($uri, 'telescope') !== false;
    }
}
