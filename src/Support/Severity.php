<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Support;

/**
 * Severity levels for findings.
 *
 * PHP 7.3-compatible string constants (not native enums).
 */
final class Severity
{
    public const CRITICAL = 'critical';

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    public const INFO = 'info';

    /**
     * Ordered from most to least severe for CI threshold comparisons.
     *
     * @var array<int, string>
     */
    public const ORDER = [
        self::CRITICAL,
        self::HIGH,
        self::MEDIUM,
        self::LOW,
        self::INFO,
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return self::ORDER;
    }

    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::ORDER, true);
    }

    /**
     * Returns true if $severity is at least as severe as $threshold.
     */
    public static function meetsThreshold(string $severity, string $threshold): bool
    {
        $severityIndex = array_search($severity, self::ORDER, true);
        $thresholdIndex = array_search($threshold, self::ORDER, true);

        if ($severityIndex === false || $thresholdIndex === false) {
            return false;
        }

        return $severityIndex <= $thresholdIndex;
    }
}
