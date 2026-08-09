<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Console;

use Illuminate\Console\Command;
use SdPayHub\Wraith\Baseline\BaselineStore;
use SdPayHub\Wraith\Pipeline\AnalyzerPipeline;

final class WraithBaselineCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wraith:baseline
                            {--only= : Comma-separated categories to run}
                            {--except= : Comma-separated categories to skip}
                            {--dynamic : Include dynamic analyzers when writing the baseline}
                            {--path= : Override baseline file path}';

    /**
     * @var string
     */
    protected $description = 'Write current Wraith findings to a baseline file (accepted debt)';

    /** @var AnalyzerPipeline */
    private $pipeline;

    public function __construct(AnalyzerPipeline $pipeline)
    {
        parent::__construct();
        $this->pipeline = $pipeline;
    }

    public function handle(): int
    {
        $only = $this->parseList($this->option('only'));
        $except = $this->parseList($this->option('except'));
        $dynamic = (bool) $this->option('dynamic');

        $path = $this->option('path');
        $path = is_string($path) && $path !== ''
            ? $path
            : (string) (config('wraith.baseline.path') ?: storage_path('wraith/baseline.json'));

        $this->info('Running Wraith to capture baseline...');

        $report = $this->pipeline->runRaw($only, $except, $dynamic);
        $store = new BaselineStore($path);
        $count = $store->write($report);

        $this->info(sprintf('Wrote %d finding(s) to %s', $count, $store->path()));
        $this->line('Tip: commit the baseline (or keep it local). New issues still fail CI with --ci --diff.');

        return 0;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function parseList($value): array
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));
    }
}
