<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Security;

use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;
use Symfony\Component\Process\Process;

/**
 * Security configuration and dependency-audit wrapping.
 *
 * Does not maintain a vulnerability database — wraps composer audit and
 * npm/pnpm audit. Secret scanning is an integration point (suggest gitleaks).
 */
final class SecurityAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::SECURITY;
    }

    public function name(): string
    {
        return 'Security';
    }

    public function supports(): bool
    {
        return true;
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];
        $findings = array_merge($findings, $this->checkDebug());
        $findings = array_merge($findings, $this->checkSession());
        $findings = array_merge($findings, $this->checkCookies());
        $findings = array_merge($findings, $this->checkHttps());
        $findings = array_merge($findings, $this->checkEnvExposure());
        $findings = array_merge($findings, $this->checkComposerAudit());
        $findings = array_merge($findings, $this->checkFrontendAudit());
        $findings = array_merge($findings, $this->checkGitleaksHint());
        $findings = array_merge($findings, $this->checkExposedDevTools());
        $findings = array_merge($findings, $this->checkTrustedProxies());
        $findings = array_merge($findings, $this->checkCors());
        $findings = array_merge($findings, $this->checkSanctumSessionDomains());
        $findings = array_merge($findings, $this->checkPublicStorageExecutables());
        $findings = array_merge($findings, $this->checkAbandonedPackages());

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkDebug(): array
    {
        if (! $this->isProduction() && (bool) config('app.debug') === true) {
            return [];
        }

        if ($this->isProduction() && (bool) config('app.debug') === true) {
            return [
                $this->finding(
                    Severity::CRITICAL,
                    $this->category(),
                    'security.debug_enabled',
                    'APP_DEBUG=true outside a local environment.',
                    'Exposes sensitive exception details and environment data.',
                    'Set APP_DEBUG=false for non-local environments.',
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
    private function checkSession(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        $findings = [];

        if ((bool) config('session.secure') !== true) {
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'security.session_insecure',
                'Session cookies are not marked secure in production.',
                'Session cookies can be sent over plain HTTP and intercepted.',
                'Set SESSION_SECURE_COOKIE=true (and serve over HTTPS).'
            );
        }

        if ((bool) config('session.http_only', true) !== true) {
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'security.session_http_only',
                'Session cookies are accessible to JavaScript (http_only=false).',
                'XSS attacks can steal session identifiers.',
                'Ensure session.http_only is true in config/session.php.'
            );
        }

        $sameSite = strtolower((string) config('session.same_site', 'lax'));

        if (! in_array($sameSite, ['lax', 'strict'], true)) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'security.session_same_site',
                sprintf('SESSION_SAME_SITE is "%s"; prefer lax or strict.', $sameSite),
                'Weak SameSite settings increase CSRF risk for cookie-based sessions.',
                'Set SESSION_SAME_SITE=lax or strict.'
            );
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkCookies(): array
    {
        // Version-sensitive: cookie encryption middleware registration differs L8 vs L11.
        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkHttps(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        $url = (string) config('app.url', '');

        if ($url !== '' && strpos($url, 'https://') !== 0) {
            return [
                $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'security.app_url_not_https',
                    'APP_URL does not use HTTPS in production.',
                    'Generated URLs, redirects, and signed URLs may be insecure.',
                    'Set APP_URL to an https:// URL and configure trusted proxies if behind a load balancer.',
                    'https://laravel.com/docs/requests#configuring-trusted-proxies'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkEnvExposure(): array
    {
        $findings = [];
        $gitignore = function_exists('base_path') ? base_path('.gitignore') : '.gitignore';

        if (is_file($gitignore)) {
            $contents = (string) file_get_contents($gitignore);

            if (strpos($contents, '.env') === false) {
                $findings[] = $this->finding(
                    Severity::CRITICAL,
                    $this->category(),
                    'security.env_not_gitignored',
                    '.env is not listed in .gitignore.',
                    'Committing .env leaks secrets into version control.',
                    'Add `.env` to .gitignore.',
                    null,
                    true,
                    ['fix' => 'gitignore_env']
                );
            }
        }

        $publicEnv = function_exists('public_path') ? public_path('.env') : null;

        if ($publicEnv !== null && is_file($publicEnv)) {
            $findings[] = $this->finding(
                Severity::CRITICAL,
                $this->category(),
                'security.env_in_public',
                'A .env file exists inside the public directory.',
                'Web servers may serve it directly, exposing all secrets.',
                'Remove public/.env immediately and rotate all credentials.'
            );
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkComposerAudit(): array
    {
        $composer = (string) config('wraith.tools.composer', 'composer');
        $base = function_exists('base_path') ? base_path() : getcwd();

        if (! is_file($base.'/composer.lock')) {
            return [
                $this->finding(
                    Severity::LOW,
                    $this->category(),
                    'security.composer_lock_missing',
                    'composer.lock is missing; dependency audit skipped.',
                    'Without a lockfile, installs are non-reproducible and audits are incomplete.',
                    'Commit composer.lock and re-run Wraith.'
                ),
            ];
        }

        try {
            $process = new Process([$composer, 'audit', '--format=json']);
            $process->setWorkingDirectory($base);
            $process->setTimeout(120);
            $process->run();
        } catch (\Throwable $e) {
            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'security.composer_audit_unavailable',
                    'Could not run composer audit: '.$e->getMessage(),
                    'Backend dependency CVE scanning requires Composer 2.4+ with audit support.',
                    'Upgrade Composer and ensure `composer audit` works locally.'
                ),
            ];
        }

        $output = trim($process->getOutput().$process->getErrorOutput());
        $data = json_decode($output, true);

        if (! is_array($data)) {
            // Non-zero without JSON often means no advisories or old composer.
            if ($process->isSuccessful()) {
                return [];
            }

            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'security.composer_audit_parse',
                    'composer audit ran but output could not be parsed as JSON.',
                    'Normalized CVE reporting requires JSON audit output.',
                    'Run `composer audit --format=json` manually to inspect.'
                ),
            ];
        }

        $advisories = [];

        if (isset($data['advisories']) && is_array($data['advisories'])) {
            $advisories = $data['advisories'];
        } elseif (isset($data['abandoned'])) {
            // structure varies by composer version
            $advisories = isset($data['advisories']) ? $data['advisories'] : [];
        }

        $findings = [];
        $count = 0;

        foreach ($advisories as $package => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $count++;
                $title = is_array($item) && isset($item['title']) ? (string) $item['title'] : 'Known vulnerability';
                $cve = is_array($item) && isset($item['cve']) ? (string) $item['cve'] : '';
                $findings[] = $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'security.composer_advisory',
                    sprintf('Vulnerable dependency %s: %s%s', $package, $title, $cve !== '' ? " ({$cve})" : ''),
                    'Known CVEs in Composer packages can be exploited in production.',
                    'Update the package to a patched version (`composer update '.$package.'`).',
                    null,
                    false,
                    ['package' => $package]
                );

                if ($count >= 20) {
                    break 2;
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkFrontendAudit(): array
    {
        $base = function_exists('base_path') ? base_path() : getcwd();

        if (! is_file($base.'/package.json')) {
            return [];
        }

        $usePnpm = is_file($base.'/pnpm-lock.yaml');
        $bin = $usePnpm
            ? (string) config('wraith.tools.pnpm', 'pnpm')
            : (string) config('wraith.tools.npm', 'npm');

        try {
            $process = new Process([$bin, 'audit', '--json']);
            $process->setWorkingDirectory($base);
            $process->setTimeout(180);
            $process->run();
        } catch (\Throwable $e) {
            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'security.frontend_audit_unavailable',
                    'Could not run frontend audit: '.$e->getMessage(),
                    'Frontend dependency CVEs require npm or pnpm audit.',
                    'Install Node tooling and ensure audit commands work.'
                ),
            ];
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            $output = trim($process->getErrorOutput());
        }

        $data = json_decode($output, true);

        if (! is_array($data)) {
            return [];
        }

        $vulnCount = 0;

        if (isset($data['metadata']['vulnerabilities']) && is_array($data['metadata']['vulnerabilities'])) {
            foreach ($data['metadata']['vulnerabilities'] as $level => $n) {
                if (in_array($level, ['high', 'critical', 'moderate', 'low'], true)) {
                    $vulnCount += (int) $n;
                }
            }
        } elseif (isset($data['advisories']) && is_array($data['advisories'])) {
            $vulnCount = count($data['advisories']);
        }

        if ($vulnCount === 0) {
            return [];
        }

        return [
            $this->finding(
                Severity::HIGH,
                $this->category(),
                'security.frontend_vulnerabilities',
                sprintf('Frontend audit reported %d vulnerability(ies).', $vulnCount),
                'Vulnerable npm packages can affect build tooling and shipped assets.',
                'Run `'.($usePnpm ? 'pnpm' : 'npm').' audit` and upgrade affected packages.'
            ),
        ];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkGitleaksHint(): array
    {
        $base = function_exists('base_path') ? base_path() : getcwd();
        $hasConfig = is_file($base.'/.gitleaks.toml') || is_file($base.'/gitleaks.toml');

        if ($hasConfig) {
            return [];
        }

        return [
            $this->finding(
                Severity::INFO,
                $this->category(),
                'security.gitleaks_suggested',
                'No gitleaks configuration detected.',
                'Wraith does not implement secret scanning; a dedicated tool is safer and more complete.',
                'Add gitleaks (or similar) to CI: https://github.com/gitleaks/gitleaks',
                'https://github.com/gitleaks/gitleaks'
            ),
        ];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkExposedDevTools(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        $findings = [];

        $tools = [
            'laravel/telescope' => [
                'class' => 'Laravel\\Telescope\\Telescope',
                'code' => 'security.telescope_in_production',
                'name' => 'Laravel Telescope',
                'gate' => 'viewTelescope',
            ],
            'laravel/horizon' => [
                'class' => 'Laravel\\Horizon\\Horizon',
                'code' => 'security.horizon_in_production',
                'name' => 'Laravel Horizon',
                'gate' => 'viewHorizon',
            ],
            'laravel/pulse' => [
                'class' => 'Laravel\\Pulse\\Pulse',
                'code' => 'security.pulse_in_production',
                'name' => 'Laravel Pulse',
                'gate' => 'viewPulse',
            ],
            'barryvdh/laravel-debugbar' => [
                'class' => 'Barryvdh\\Debugbar\\LaravelDebugbar',
                'code' => 'security.debugbar_in_production',
                'name' => 'Laravel Debugbar',
                'gate' => null,
            ],
        ];

        foreach ($tools as $package => $meta) {
            if (! class_exists($meta['class'])) {
                continue;
            }

            if ($package === 'barryvdh/laravel-debugbar' || $meta['gate'] === null) {
                $findings[] = $this->finding(
                    Severity::CRITICAL,
                    $this->category(),
                    $meta['code'],
                    $meta['name'].' appears installed in a production environment.',
                    'Debug tooling can expose queries, headers, and application internals.',
                    'Remove '.$package.' from production or ensure it is disabled when APP_ENV=production.',
                    null,
                    false,
                    ['package' => $package]
                );
                continue;
            }

            // Gate may exist but still be wide open (return true). Flag presence + remind to authorize.
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                $meta['code'],
                $meta['name'].' is installed in production.',
                'Unauthenticated dashboard access leaks jobs, requests, and sensitive metrics.',
                'Gate access with Gate::define(\''.$meta['gate'].'\', ...) and restrict to trusted users.',
                null,
                false,
                ['package' => $package, 'gate' => $meta['gate']]
            );
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkTrustedProxies(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        // Version-sensitive: TrustProxies middleware / bootstrap config differs L8–L11.
        $trustProxies = null;

        if (class_exists(\App\Http\Middleware\TrustProxies::class)) {
            $trustProxies = \App\Http\Middleware\TrustProxies::class;
        } elseif (class_exists(\Illuminate\Http\Middleware\TrustProxies::class)) {
            $trustProxies = \Illuminate\Http\Middleware\TrustProxies::class;
        }

        if ($trustProxies === null) {
            return [
                $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'security.trusted_proxies_missing',
                    'No TrustProxies middleware class was found.',
                    'Behind Cloudflare/ALB/nginx, HTTPS and client IPs are wrong without trusted proxies.',
                    'Configure TrustProxies for your load balancer / CDN.',
                    'https://laravel.com/docs/requests#configuring-trusted-proxies'
                ),
            ];
        }

        try {
            $ref = new \ReflectionClass($trustProxies);
            if ($ref->hasProperty('proxies')) {
                $prop = $ref->getProperty('proxies');
                $prop->setAccessible(true);
                $defaults = $ref->getDefaultProperties();
                $proxies = array_key_exists('proxies', $defaults) ? $defaults['proxies'] : null;

                if ($proxies === null || $proxies === [] || $proxies === '') {
                    return [
                        $this->finding(
                            Severity::HIGH,
                            $this->category(),
                            'security.trusted_proxies_empty',
                            'TrustProxies $proxies looks empty/unset.',
                            'Empty trusted proxies break URL scheme and IP detection behind a reverse proxy.',
                            'Set TrustProxies to your proxy IPs, or "*" only when you fully control the network edge.',
                            'https://laravel.com/docs/requests#configuring-trusted-proxies'
                        ),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Reflection failures should not abort the analyzer.
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkCors(): array
    {
        $paths = [
            config_path('cors.php'),
            base_path('config/cors.php'),
        ];

        $corsFile = null;

        foreach ($paths as $path) {
            if (is_file($path)) {
                $corsFile = $path;
                break;
            }
        }

        if ($corsFile === null) {
            return [];
        }

        $origins = config('cors.allowed_origins', []);
        $supportsCredentials = (bool) config('cors.supports_credentials', false);

        if (! is_array($origins)) {
            $origins = [];
        }

        if (in_array('*', $origins, true) && $supportsCredentials) {
            return [
                $this->finding(
                    Severity::CRITICAL,
                    $this->category(),
                    'security.cors_star_with_credentials',
                    'CORS allows origin "*" with supports_credentials=true.',
                    'Browsers reject or dangerously mis-handle credentialed wildcard CORS; this is a common misconfig.',
                    'List explicit origins in config/cors.php and keep credentials only for trusted frontends.'
                ),
            ];
        }

        if (in_array('*', $origins, true) && $this->isProduction()) {
            return [
                $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'security.cors_wildcard',
                    'CORS allowed_origins includes "*" in production.',
                    'Wildcard CORS lets any website call your API from a browser context.',
                    'Replace "*" with explicit trusted frontend origins.'
                ),
            ];
        }

        return [];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkSanctumSessionDomains(): array
    {
        if (! class_exists(\Laravel\Sanctum\Sanctum::class) && ! is_file(config_path('sanctum.php'))) {
            return [];
        }

        $findings = [];
        $sessionDomain = (string) config('session.domain', '');
        $stateful = config('sanctum.stateful', []);

        if (! is_array($stateful)) {
            $stateful = [];
        }

        $stateful = array_values(array_filter(array_map('strval', $stateful)));

        if ($stateful === [] && $this->isProduction()) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'security.sanctum_stateful_empty',
                'Sanctum stateful domains list is empty in production.',
                'SPA cookie authentication will fail without SANCTUM_STATEFUL_DOMAINS.',
                'Set SANCTUM_STATEFUL_DOMAINS to your frontend hosts.',
                'https://laravel.com/docs/sanctum#spa-authentication'
            );
        }

        if ($sessionDomain !== '' && $stateful !== []) {
            $normalizedSession = ltrim($sessionDomain, '.');
            $overlap = false;

            foreach ($stateful as $host) {
                $host = preg_replace('/:\d+$/', '', $host) ?: $host;
                if ($host === $normalizedSession || substr($host, -strlen($normalizedSession)) === $normalizedSession) {
                    $overlap = true;
                    break;
                }
            }

            if (! $overlap) {
                $findings[] = $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'security.sanctum_session_domain_mismatch',
                    'SESSION_DOMAIN does not appear to align with Sanctum stateful domains.',
                    'Cookie auth for SPAs silently fails when session domain and Sanctum hosts disagree.',
                    'Align SESSION_DOMAIN with SANCTUM_STATEFUL_DOMAINS (and HTTPS/SameSite settings).',
                    'https://laravel.com/docs/sanctum#spa-authentication',
                    false,
                    ['session_domain' => $sessionDomain, 'stateful' => $stateful]
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkPublicStorageExecutables(): array
    {
        $dir = storage_path('app/public');

        if (! is_dir($dir)) {
            return [];
        }

        $dangerous = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $checked++;
            if ($checked > 500) {
                break;
            }

            $ext = strtolower($file->getExtension());

            if (in_array($ext, ['php', 'phtml', 'phar', 'cgi', 'exe', 'sh', 'bat'], true)) {
                $dangerous[] = $file->getPathname();
                if (count($dangerous) >= 5) {
                    break;
                }
            }
        }

        if ($dangerous === []) {
            return [];
        }

        return [
            $this->finding(
                Severity::CRITICAL,
                $this->category(),
                'security.public_storage_executable',
                'Executable-like files found under storage/app/public (served via storage:link).',
                'User-uploaded PHP/shell files in the public disk can become a web shell.',
                'Remove those files, block dangerous extensions on upload, and serve user files outside the web root when possible.',
                null,
                false,
                ['samples' => $dangerous]
            ),
        ];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkAbandonedPackages(): array
    {
        $composer = (string) config('wraith.tools.composer', 'composer');
        $base = function_exists('base_path') ? base_path() : getcwd();

        if (! is_file($base.'/composer.lock')) {
            return [];
        }

        try {
            $process = new Process([$composer, 'audit', '--abandoned=report', '--format=json']);
            $process->setWorkingDirectory($base);
            $process->setTimeout(120);
            $process->run();
        } catch (\Throwable $e) {
            return [];
        }

        $output = trim($process->getOutput().$process->getErrorOutput());
        $data = json_decode($output, true);

        if (! is_array($data) || ! isset($data['abandoned']) || ! is_array($data['abandoned']) || $data['abandoned'] === []) {
            return [];
        }

        $names = array_slice(array_keys($data['abandoned']), 0, 15);

        return [
            $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'security.abandoned_packages',
                sprintf('%d abandoned Composer package(s) detected (e.g. %s).', count($data['abandoned']), implode(', ', array_slice($names, 0, 5))),
                'Abandoned packages stop receiving security and Laravel compatibility fixes.',
                'Replace or remove abandoned dependencies listed by `composer audit --abandoned=report`.',
                null,
                false,
                ['packages' => $names]
            ),
        ];
    }
}
