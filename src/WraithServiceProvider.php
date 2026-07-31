<?php

declare(strict_types=1);

namespace SdPayHub\Wraith;

use Illuminate\Support\ServiceProvider;
use SdPayHub\Wraith\Console\WraithCommand;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Contracts\DynamicAnalyzer;
use SdPayHub\Wraith\Fix\SafeFixer;
use SdPayHub\Wraith\Pipeline\AnalyzerPipeline;
use SdPayHub\Wraith\Scoring\Scorer;

final class WraithServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wraith.php', 'wraith');

        $this->app->singleton(Scorer::class, function ($app) {
            return new Scorer(
                (array) config('wraith.weights', []),
                (array) config('wraith.severity_penalties', [])
            );
        });

        $this->app->singleton(SafeFixer::class, function ($app) {
            $backup = config('wraith.fix.backup_path');
            $backup = is_string($backup) && $backup !== ''
                ? $backup
                : storage_path('wraith/backups');

            return new SafeFixer($backup, (array) config('wraith.fix.enabled', []));
        });

        $this->app->singleton(AnalyzerPipeline::class, function ($app) {
            $analyzers = [];

            foreach ((array) config('wraith.analyzers', []) as $class) {
                if (! is_string($class) || ! class_exists($class)) {
                    continue;
                }

                $instance = $app->make($class);

                if ($instance instanceof Analyzer) {
                    $analyzers[] = $instance;
                }
            }

            $dynamic = [];

            foreach ((array) config('wraith.dynamic_analyzers', []) as $class) {
                if (! is_string($class) || ! class_exists($class)) {
                    continue;
                }

                $instance = $app->make($class);

                if ($instance instanceof DynamicAnalyzer) {
                    $dynamic[] = $instance;
                }
            }

            return new AnalyzerPipeline($analyzers, $dynamic, $app->make(Scorer::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WraithCommand::class]);

            $this->publishes([
                __DIR__.'/../config/wraith.php' => config_path('wraith.php'),
            ], 'wraith-config');
        }
    }
}
