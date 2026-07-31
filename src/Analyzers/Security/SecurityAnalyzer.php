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
}
