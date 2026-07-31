<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Configuration;

use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;

/**
 * Configuration sanity: missing env keys, wrong types, deprecated keys.
 *
 * Version-sensitive: deprecated config keys differ across Laravel majors.
 */
final class ConfigurationAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::CONFIGURATION;
    }

    public function name(): string
    {
        return 'Configuration';
    }

    public function supports(): bool
    {
        return true;
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];
        $findings = array_merge($findings, $this->checkRequiredEnv());
        $findings = array_merge($findings, $this->checkBoolTypes());
        $findings = array_merge($findings, $this->checkDeprecatedKeys());
        $findings = array_merge($findings, $this->checkEnvExampleDrift());
        $findings = array_merge($findings, $this->checkComposerPhpPlatform());

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkRequiredEnv(): array
    {
        $required = ['APP_KEY', 'APP_URL'];
        $findings = [];

        foreach ($required as $key) {
            $value = env($key);

            if ($value === null || $value === '') {
                $findings[] = $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'config.missing_env',
                    sprintf('Required environment variable %s is missing or empty.', $key),
                    'Missing core env values cause silent misconfiguration or boot failures.',
                    sprintf('Set %s in your .env file.', $key),
                    null,
                    false,
                    ['key' => $key]
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkBoolTypes(): array
    {
        $findings = [];
        $boolKeys = ['APP_DEBUG', 'SESSION_SECURE_COOKIE'];

        foreach ($boolKeys as $key) {
            $raw = env($key);

            if ($raw === null) {
                continue;
            }

            if (is_bool($raw)) {
                continue;
            }

            $normalized = strtolower(trim((string) $raw));

            if (! in_array($normalized, ['true', 'false', '1', '0', '(true)', '(false)', 'yes', 'no', 'on', 'off'], true)) {
                $findings[] = $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'config.invalid_bool_env',
                    sprintf('%s has a non-boolean-like value: "%s".', $key, (string) $raw),
                    'Laravel may coerce unexpected strings incorrectly, flipping feature flags.',
                    sprintf('Set %s to true or false.', $key),
                    null,
                    true,
                    ['fix' => 'env_bool_normalize', 'key' => $key]
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkDeprecatedKeys(): array
    {
        $findings = [];
        $major = $this->laravelMajor();

        // Version-sensitive: hash_driver moved / renamed across versions.
        if ($major >= 11 && config()->has('hashing.driver') === false && env('HASH_DRIVER') !== null) {
            $findings[] = $this->finding(
                Severity::LOW,
                $this->category(),
                'config.deprecated_hash_driver_env',
                'HASH_DRIVER env may be unused on Laravel 11+.',
                'Deprecated env keys create a false sense of configuration.',
                'Review config/hashing.php for the current API.',
                'https://laravel.com/docs/hashing'
            );
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkEnvExampleDrift(): array
    {
        $example = base_path('.env.example');
        $configDir = config_path();

        if (! is_file($example) || ! is_dir($configDir)) {
            return [];
        }

        $exampleContents = (string) file_get_contents($example);
        $exampleKeys = [];

        foreach (preg_split('/\R/', $exampleContents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            $exampleKeys[] = trim(explode('=', $line, 2)[0]);
        }

        $usedKeys = [];
        foreach (glob($configDir.'/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match_all("/env\(\s*['\"]([A-Z0-9_]+)['\"]/", $contents, $matches)) {
                foreach ($matches[1] as $key) {
                    $usedKeys[$key] = true;
                }
            }
        }

        $missing = [];

        foreach (array_keys($usedKeys) as $key) {
            if (in_array($key, ['APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL'], true)) {
                continue;
            }
            if (! in_array($key, $exampleKeys, true)) {
                $missing[] = $key;
            }
        }

        $missing = array_slice($missing, 0, 20);

        if ($missing === []) {
            return [];
        }

        return [
            $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'config.env_example_drift',
                sprintf('%d env key(s) used in config/*.php are missing from .env.example (e.g. %s).', count($missing), implode(', ', array_slice($missing, 0, 5))),
                'Drifting .env.example causes broken deploys and painful onboarding.',
                'Add the missing keys to .env.example with safe placeholder values.',
                null,
                false,
                ['missing' => $missing]
            ),
        ];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkComposerPhpPlatform(): array
    {
        $composerFile = base_path('composer.json');

        if (! is_file($composerFile)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($composerFile), true);

        if (! is_array($json)) {
            return [];
        }

        $platformPhp = null;

        if (isset($json['config']['platform']['php'])) {
            $platformPhp = (string) $json['config']['platform']['php'];
        }

        $requirePhp = isset($json['require']['php']) ? (string) $json['require']['php'] : null;
        $runtime = PHP_VERSION;

        if ($platformPhp !== null && version_compare($runtime, $platformPhp, '!=')) {
            // Loose: major.minor mismatch is the painful case
            $runtimeMm = implode('.', array_slice(explode('.', $runtime), 0, 2));
            $platformMm = implode('.', array_slice(explode('.', $platformPhp), 0, 2));

            if ($runtimeMm !== $platformMm) {
                return [
                    $this->finding(
                        Severity::MEDIUM,
                        $this->category(),
                        'config.composer_platform_php_mismatch',
                        sprintf('composer.json platform.php is %s but runtime PHP is %s.', $platformPhp, $runtime),
                        'Platform pins make Composer resolve packages for a different PHP than production runs.',
                        'Align config.platform.php with the PHP version used in production, or remove the pin.',
                        null,
                        false,
                        ['platform_php' => $platformPhp, 'runtime' => $runtime]
                    ),
                ];
            }
        }

        if ($requirePhp !== null && ! $this->phpConstraintSatisfied($requirePhp, $runtime)) {
            return [
                $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'config.composer_php_constraint_unsatisfied',
                    sprintf('Runtime PHP %s does not satisfy composer.json require.php (%s).', $runtime, $requirePhp),
                    'Running outside the declared PHP constraint risks subtle failures and unsupported packages.',
                    'Upgrade PHP or loosen/adjust the require.php constraint to match production.',
                    null,
                    false,
                    ['constraint' => $requirePhp, 'runtime' => $runtime]
                ),
            ];
        }

        return [];
    }

    private function phpConstraintSatisfied(string $constraint, string $version): bool
    {
        // Minimal checker for common forms: ^8.1, >=8.0, ^7.3|^8.0
        $parts = preg_split('/\s*\|\|?\s*/', $constraint) ?: [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (strpos($part, '^') === 0) {
                $base = ltrim($part, '^');
                $baseParts = explode('.', $base);
                $major = (int) ($baseParts[0] ?? 0);
                $minor = (int) ($baseParts[1] ?? 0);
                $verParts = explode('.', $version);
                $vMajor = (int) ($verParts[0] ?? 0);
                $vMinor = (int) ($verParts[1] ?? 0);

                if ($vMajor === $major && $vMinor >= $minor) {
                    return true;
                }
                if ($vMajor === $major + 1 && $major >= 7) {
                    // ^7.3 allows <8.0 only; ^8.1 allows <9.0
                    continue;
                }
                if ($vMajor === $major) {
                    return $vMinor >= $minor;
                }
            }

            if (strpos($part, '>=') === 0) {
                $base = trim(substr($part, 2));
                if (version_compare($version, $base, '>=')) {
                    return true;
                }
            }

            if (preg_match('/^\d+\.\d+/', $part) && version_compare($version, $part, '>=')) {
                return true;
            }
        }

        // If we cannot parse safely, do not false-alarm.
        return true;
    }
}
