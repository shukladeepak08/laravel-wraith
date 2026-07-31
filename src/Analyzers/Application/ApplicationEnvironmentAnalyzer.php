<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Application;

use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;

/**
 * Application & environment sanity checks.
 */
final class ApplicationEnvironmentAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::APPLICATION;
    }

    public function name(): string
    {
        return 'Application & Environment';
    }

    public function supports(): bool
    {
        return true;
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];

        $findings = array_merge($findings, $this->checkDebug());
        $findings = array_merge($findings, $this->checkAppKey());
        $findings = array_merge($findings, $this->checkMaintenance());
        $findings = array_merge($findings, $this->checkTimezone());
        $findings = array_merge($findings, $this->checkLocale());
        $findings = array_merge($findings, $this->checkStorageLink());
        $findings = array_merge($findings, $this->checkCaches());
        $findings = array_merge($findings, $this->checkStoragePermissions());
        $findings = array_merge($findings, $this->checkScheduleHint());

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkDebug(): array
    {
        if ($this->isProduction() && (bool) config('app.debug') === true) {
            return [
                $this->finding(
                    Severity::CRITICAL,
                    $this->category(),
                    'app.debug_in_production',
                    'APP_DEBUG is enabled in a production environment.',
                    'Debug mode exposes stack traces, environment details, and sensitive data to end users.',
                    'Set APP_DEBUG=false in your production .env and clear config cache.',
                    'https://laravel.com/docs/configuration#debug-mode',
                    true,
                    ['fix' => 'env_bool_normalize', 'key' => 'APP_DEBUG', 'value' => 'false']
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkAppKey(): array
    {
        $key = (string) config('app.key', '');

        if ($key === '') {
            return [
                $this->finding(
                    Severity::CRITICAL,
                    $this->category(),
                    'app.key_missing',
                    'APP_KEY is missing.',
                    'Without an application key, encryption, cookies, and sessions cannot be secured.',
                    'Run `php artisan key:generate` and deploy the key securely.',
                    'https://laravel.com/docs/encryption'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkMaintenance(): array
    {
        if (function_exists('app') && app()->isDownForMaintenance()) {
            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'app.maintenance_mode',
                    'Application is currently in maintenance mode.',
                    'Maintenance mode is intentional during deploys but unexpected otherwise.',
                    'Run `php artisan up` when maintenance is complete.'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkTimezone(): array
    {
        $timezone = (string) config('app.timezone', 'UTC');

        if ($timezone === '' || @timezone_open($timezone) === false) {
            return [
                $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'app.invalid_timezone',
                    sprintf('Configured timezone "%s" is invalid.', $timezone),
                    'Invalid timezones cause incorrect scheduling, logging, and date display.',
                    'Set APP_TIMEZONE / config app.timezone to a valid PHP timezone (e.g. UTC).'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkLocale(): array
    {
        $locale = (string) config('app.locale', 'en');

        if ($locale === '') {
            return [
                $this->finding(
                    Severity::LOW,
                    $this->category(),
                    'app.empty_locale',
                    'Application locale is empty.',
                    'An empty locale breaks translations and localization helpers.',
                    'Set APP_LOCALE to a valid locale string (e.g. en).'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkStorageLink(): array
    {
        $publicStorage = public_path('storage');

        if (! file_exists($publicStorage)) {
            return [
                $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'app.storage_link_missing',
                    'The public/storage symlink is missing.',
                    'User-uploaded files served via Storage::url() will 404 without the symlink.',
                    'Run `php artisan storage:link`.',
                    'https://laravel.com/docs/filesystem#the-public-disk'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkCaches(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        $findings = [];
        $base = function_exists('base_path') ? base_path() : getcwd();

        // Laravel 8 may use routes.php; L11+ may use routes-v7.php — check both.
        // Version-sensitive: route cache filename differs across Laravel majors.
        $routeCacheCandidates = [
            $base.'/bootstrap/cache/routes-v7.php',
            $base.'/bootstrap/cache/routes.php',
        ];

        if (! file_exists($base.'/bootstrap/cache/config.php')) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'app.config_cache_missing',
                'Config cache is not present in production.',
                'Uncached config means every request reloads all config files.',
                'Run `php artisan config:cache` as part of your deploy.',
                'https://laravel.com/docs/deployment#optimization'
            );
        }

        $hasRouteCache = false;

        foreach ($routeCacheCandidates as $candidate) {
            if (file_exists($candidate)) {
                $hasRouteCache = true;
                break;
            }
        }

        if (! $hasRouteCache) {
            $findings[] = $this->finding(
                Severity::LOW,
                $this->category(),
                'app.route_cache_missing',
                'Route cache is not present in production.',
                'Uncached routes slow bootstrapping on every request.',
                'Run `php artisan route:cache` as part of your deploy (closure routes cannot be cached).',
                'https://laravel.com/docs/deployment#optimization'
            );
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkStoragePermissions(): array
    {
        $paths = [
            storage_path(),
            storage_path('logs'),
            storage_path('framework'),
            storage_path('app'),
            base_path('bootstrap/cache'),
        ];

        $findings = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            if (! is_writable($path)) {
                $findings[] = $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'app.storage_not_writable',
                    sprintf('Directory is not writable: %s', $path),
                    'Laravel cannot write logs, cache, sessions, or compiled files without writable storage paths.',
                    'Fix ownership/permissions so the PHP/web user can write to storage/ and bootstrap/cache.',
                    'https://laravel.com/docs/deployment#server-configuration',
                    false,
                    ['path' => $path]
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkScheduleHint(): array
    {
        $hasSchedule = false;

        $kernel = app_path('Console/Kernel.php');
        if (is_file($kernel)) {
            $contents = (string) file_get_contents($kernel);
            if (strpos($contents, '->daily(') !== false
                || strpos($contents, '->hourly(') !== false
                || strpos($contents, '->everyMinute(') !== false
                || strpos($contents, 'schedule(') !== false) {
                $hasSchedule = true;
            }
        }

        $routesConsole = base_path('routes/console.php');
        if (is_file($routesConsole)) {
            $contents = (string) file_get_contents($routesConsole);
            if (strpos($contents, 'Schedule::') !== false) {
                $hasSchedule = true;
            }
        }

        if (! $hasSchedule || ! $this->isProduction()) {
            return [];
        }

        return [
            $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'app.schedule_requires_cron',
                'Scheduled tasks are defined, but Wraith cannot verify cron is installed on the server.',
                'Without a cron entry for `php artisan schedule:run`, scheduled jobs never fire in production.',
                'Ensure the server crontab runs `* * * * * php /path/to/artisan schedule:run` (or use your host\'s scheduler).',
                'https://laravel.com/docs/scheduling#running-the-scheduler'
            ),
        ];
    }
}
