<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Analyzers
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class names of analyzers registered by default.
    | Remove or comment entries to disable specific analyzers.
    |
    */

    'analyzers' => [
        \SdPayHub\Wraith\Analyzers\Application\ApplicationEnvironmentAnalyzer::class,
        \SdPayHub\Wraith\Analyzers\Security\SecurityAnalyzer::class,
        \SdPayHub\Wraith\Analyzers\Configuration\ConfigurationAnalyzer::class,
        \SdPayHub\Wraith\Analyzers\Database\DatabaseSchemaAnalyzer::class,
        \SdPayHub\Wraith\Analyzers\Eloquent\EloquentAnalyzer::class,
        \SdPayHub\Wraith\Analyzers\Routes\RoutesAnalyzer::class,
        \SdPayHub\Wraith\Analyzers\Performance\PerformanceAssetsAnalyzer::class,
        \SdPayHub\Wraith\Analyzers\CodeQuality\CodeQualityAnalyzer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic Analyzers (opt-in via --dynamic)
    |--------------------------------------------------------------------------
    */

    'dynamic_analyzers' => [
        \SdPayHub\Wraith\Analyzers\Dynamic\QueryPatternAnalyzer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Category Score Weights
    |--------------------------------------------------------------------------
    |
    | Overall score = weighted average of category scores.
    | Weights must be positive; they are normalized at runtime.
    | Formula is documented in the README — not a black box.
    |
    */

    'weights' => [
        'application' => 1.0,
        'security' => 2.0,
        'configuration' => 1.0,
        'database' => 1.5,
        'eloquent' => 1.0,
        'routes' => 1.0,
        'performance' => 1.0,
        'code_quality' => 1.0,
        'dynamic' => 1.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity Score Penalties
    |--------------------------------------------------------------------------
    |
    | Each finding subtracts its severity penalty from the category's starting
    | score of 100. Floor is 0.
    |
    */

    'severity_penalties' => [
        'critical' => 25,
        'high' => 15,
        'medium' => 8,
        'low' => 3,
        'info' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | CI Defaults
    |--------------------------------------------------------------------------
    */

    'fail_on' => 'high',

    /*
    |--------------------------------------------------------------------------
    | Safe Auto-Fix
    |--------------------------------------------------------------------------
    */

    'fix' => [
        // Resolved relative to storage_path at runtime if left null.
        'backup_path' => null,
        'enabled' => [
            'gitignore_env',
            'env_bool_normalize',
            'pint',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic Mode
    |--------------------------------------------------------------------------
    |
    | Default: GET-only routes with no required parameters.
    | Non-GET methods require explicit allowlisting below.
    |
    */

    'dynamic' => [
        'methods' => ['GET'],
        'allow_non_get' => false,
        'route_patterns' => ['*'],
        'max_routes' => 50,
        'slow_query_ms' => 100,
        'n_plus_one_threshold' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | External Tool Paths
    |--------------------------------------------------------------------------
    */

    'tools' => [
        'composer' => 'composer',
        'npm' => 'npm',
        'pnpm' => 'pnpm',
        'phpstan' => 'vendor/bin/phpstan',
        'pint' => 'vendor/bin/pint',
    ],

];
