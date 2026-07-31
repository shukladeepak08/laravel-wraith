<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers;

use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Results\Finding;

/**
 * Shared helpers for building analysis results.
 */
abstract class AbstractAnalyzer
{
    /**
     * @param array<int, Finding> $findings
     */
    protected function result(string $name, string $category, array $findings): AnalysisResult
    {
        return new AnalysisResult($name, $category, $findings);
    }

    protected function finding(
        string $severity,
        string $category,
        string $code,
        string $description,
        string $whyItMatters,
        string $suggestedFix,
        $docUrl = null,
        bool $autoFixable = false,
        array $meta = []
    ): Finding {
        return new Finding(
            $severity,
            $category,
            $code,
            $description,
            $whyItMatters,
            $suggestedFix,
            $docUrl,
            $autoFixable,
            $meta
        );
    }

    protected function appEnv(): string
    {
        return (string) config('app.env', 'production');
    }

    protected function isProduction(): bool
    {
        return in_array($this->appEnv(), ['production', 'prod'], true);
    }

    protected function laravelVersion(): string
    {
        if (function_exists('app')) {
            try {
                return (string) app()->version();
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return '0.0.0';
    }

    /**
     * Major Laravel version as int. Version-sensitive analyzers use this.
     */
    protected function laravelMajor(): int
    {
        $version = $this->laravelVersion();
        $parts = explode('.', $version);

        return (int) ($parts[0] ?? 0);
    }
}
