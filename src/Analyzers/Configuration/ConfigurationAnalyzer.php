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
}
