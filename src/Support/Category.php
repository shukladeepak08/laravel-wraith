<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Support;

/**
 * Analyzer category identifiers used by --only / --except.
 */
final class Category
{
    public const APPLICATION = 'application';

    public const SECURITY = 'security';

    public const CONFIGURATION = 'configuration';

    public const DATABASE = 'database';

    public const ELOQUENT = 'eloquent';

    public const ROUTES = 'routes';

    public const PERFORMANCE = 'performance';

    public const CODE_QUALITY = 'code_quality';

    public const DYNAMIC = 'dynamic';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::APPLICATION,
            self::SECURITY,
            self::CONFIGURATION,
            self::DATABASE,
            self::ELOQUENT,
            self::ROUTES,
            self::PERFORMANCE,
            self::CODE_QUALITY,
            self::DYNAMIC,
        ];
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::all(), true);
    }
}
