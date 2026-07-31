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
        $findings = array_merge($findings, $this->checkProductionServiceDrivers());
        $findings = array_merge($findings, $this->checkRedisPrefixes());
        $findings = array_merge($findings, $this->checkQueueTimeouts());
        $findings = array_merge($findings, $this->checkFailedJobsTable());

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

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkProductionServiceDrivers(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        $findings = [];
        $mailer = (string) config('mail.default', config('mail.driver', 'smtp'));

        if (in_array($mailer, ['log', 'array'], true)) {
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'performance.mail_driver_non_delivery',
                sprintf('Mailer is `%s` in production — mail will not be delivered to users.', $mailer),
                'Password resets, invoices, and alerts silently go to logs instead of inboxes.',
                'Set MAIL_MAILER to smtp, ses, postmark, mailgun, or another real transport.'
            );
        }

        $disk = (string) config('filesystems.default', 'local');

        if ($disk === 'local') {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'performance.filesystem_local_production',
                'Default filesystem disk is `local` in production.',
                'Local disk does not survive multi-server deploys and is easy to lose on rebuilds.',
                'Use s3 (or similar) for user uploads in production when running more than one server.'
            );
        }

        $logChannel = (string) config('logging.default', 'stack');
        $channels = (array) config('logging.channels.'.$logChannel.'.channels', []);

        if ($logChannel === 'single' || in_array('single', $channels, true)) {
            $path = (string) config('logging.channels.single.path', storage_path('logs/laravel.log'));
            if (is_file($path) && is_readable($path)) {
                $findings[] = $this->finding(
                    Severity::LOW,
                    $this->category(),
                    'performance.log_single_readable',
                    'Production uses a single/file log channel that is world-readable on disk if permissions slip.',
                    'Application logs often contain PII and tokens; prefer stderr / centralized logging in production.',
                    'Ship logs to stdout/stderr or a log aggregator; restrict storage/logs permissions.'
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkRedisPrefixes(): array
    {
        $cacheStore = (string) config('cache.default', 'file');
        $sessionDriver = (string) config('session.driver', 'file');
        $queueDriver = (string) config('queue.default', 'sync');

        $usesRedis = in_array($cacheStore, ['redis'], true)
            || in_array($sessionDriver, ['redis'], true)
            || in_array($queueDriver, ['redis'], true);

        if (! $usesRedis) {
            return [];
        }

        $cachePrefix = (string) config('cache.prefix', '');
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $horizonPrefix = (string) config('horizon.prefix', '');

        $prefixes = array_filter([
            'cache.prefix' => $cachePrefix,
            'redis.options.prefix' => $redisPrefix,
            'horizon.prefix' => $horizonPrefix,
        ]);

        if (count($prefixes) < 2) {
            if ($cachePrefix === '' && $redisPrefix === '' && $this->isProduction()) {
                return [
                    $this->finding(
                        Severity::MEDIUM,
                        $this->category(),
                        'performance.redis_prefix_empty',
                        'Redis/cache prefixes are empty while Redis is in use.',
                        'Multiple apps on one Redis without prefixes collide on sessions and cache keys.',
                        'Set a unique CACHE_PREFIX / Redis prefix per application.'
                    ),
                ];
            }

            return [];
        }

        $values = array_values($prefixes);
        if (count(array_unique($values)) === 1 && $values[0] !== '') {
            // Same non-empty prefix everywhere is OK for one app.
            return [];
        }

        // Detect identical cache + redis option prefix that equals another app smell is hard;
        // flag empty cache prefix with redis sessions.
        if ($cachePrefix === '' && in_array($sessionDriver, ['redis'], true)) {
            return [
                $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'performance.redis_session_no_cache_prefix',
                    'Redis sessions are enabled but cache/redis prefixes look empty.',
                    'Shared Redis without prefixes can mix sessions across apps/environments.',
                    'Set distinct prefixes for cache, session, and Horizon per environment.'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkQueueTimeouts(): array
    {
        $connection = (string) config('queue.default', 'sync');

        if ($connection === 'sync') {
            return [];
        }

        $retryAfter = (int) config('queue.connections.'.$connection.'.retry_after', 90);
        $timeout = (int) config('queue.connections.'.$connection.'.timeout', 0);

        // Horizon / worker timeout often lives elsewhere; use retry_after vs common job timeout heuristic.
        if ($timeout > 0 && $retryAfter <= $timeout) {
            return [
                $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'performance.queue_retry_after_too_low',
                    sprintf('Queue `%s` retry_after (%d) is <= timeout (%d).', $connection, $retryAfter, $timeout),
                    'Jobs can be released and run twice while still executing when retry_after is too low.',
                    'Set retry_after to several seconds higher than your longest job timeout.',
                    'https://laravel.com/docs/queues#job-expirations-and-timeouts'
                ),
            ];
        }

        if ($retryAfter > 0 && $retryAfter < 60 && $this->isProduction()) {
            return [
                $this->finding(
                    Severity::LOW,
                    $this->category(),
                    'performance.queue_retry_after_low',
                    sprintf('Queue `%s` retry_after is only %d seconds.', $connection, $retryAfter),
                    'Short retry_after values commonly cause duplicate job processing under load.',
                    'Raise retry_after above your worker timeout (Laravel default is often 90).'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkFailedJobsTable(): array
    {
        $connection = (string) config('queue.default', 'sync');

        if ($connection === 'sync' || ! $this->isProduction()) {
            return [];
        }

        $failedDriver = (string) config('queue.failed.driver', 'database-uuids');

        if (in_array($failedDriver, ['null', ''], true)) {
            return [
                $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'performance.failed_jobs_disabled',
                    'Failed job logging appears disabled (queue.failed.driver is null).',
                    'Without failed job storage you cannot diagnose or retry production failures.',
                    'Use the database failed-jobs driver and run the failed jobs migration.'
                ),
            ];
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('failed_jobs')
                && in_array($failedDriver, ['database', 'database-uuids'], true)) {
                return [
                    $this->finding(
                        Severity::HIGH,
                        $this->category(),
                        'performance.failed_jobs_table_missing',
                        'failed_jobs table is missing while the database failed-job driver is configured.',
                        'Failed jobs will error again when Laravel tries to persist them.',
                        'Run `php artisan queue:failed-table && php artisan migrate`.'
                    ),
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }
}
