<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Console;

use Illuminate\Console\Command;
use SdPayHub\Wraith\Fix\SafeFixer;
use SdPayHub\Wraith\Pipeline\AnalyzerPipeline;
use SdPayHub\Wraith\Reporters\HtmlReporter;
use SdPayHub\Wraith\Reporters\JsonReporter;
use SdPayHub\Wraith\Reporters\MarkdownReporter;
use SdPayHub\Wraith\Reporters\TerminalReporter;
use SdPayHub\Wraith\Scoring\Scorer;
use SdPayHub\Wraith\Support\Severity;

final class WraithCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wraith
                            {--only= : Comma-separated categories to run}
                            {--except= : Comma-separated categories to skip}
                            {--json : Output JSON}
                            {--html : Output HTML report}
                            {--markdown : Output Markdown report}
                            {--score : Score-only minimal output}
                            {--ci : Non-zero exit when severity meets --fail-on}
                            {--fail-on= : CI severity threshold (critical|high|medium|low|info)}
                            {--fix : Apply enumerated safe fixes}
                            {--dry-run : Preview fixes without writing}
                            {--restore : Restore files from the last --fix backup}
                            {--dynamic : Opt-in dynamic route replay / query analysis}
                            {--routes= : Comma-separated route patterns for --dynamic}';

    /**
     * @var string
     */
    protected $description = 'Audit this Laravel app and print a scored list of issues (try: php artisan wraith)';

    /** @var AnalyzerPipeline */
    private $pipeline;

    /** @var Scorer */
    private $scorer;

    /** @var SafeFixer */
    private $fixer;

    public function __construct(AnalyzerPipeline $pipeline, Scorer $scorer, SafeFixer $fixer)
    {
        parent::__construct();
        $this->pipeline = $pipeline;
        $this->scorer = $scorer;
        $this->fixer = $fixer;
    }

    public function handle(): int
    {
        if ((bool) $this->option('restore')) {
            $this->line($this->fixer->restore());

            return 0;
        }

        $only = $this->parseList($this->option('only'));
        $except = $this->parseList($this->option('except'));
        $dynamic = (bool) $this->option('dynamic');

        if ($dynamic && $this->option('routes')) {
            $patterns = $this->parseList($this->option('routes'));
            config(['wraith.dynamic.route_patterns' => $patterns]);
        }

        if ($dynamic) {
            $this->warn('Dynamic mode enabled: Wraith will make real GET requests to your app.');
        }

        $report = $this->pipeline->run($only, $except, $dynamic);

        if ((bool) $this->option('fix') || (bool) $this->option('dry-run')) {
            // --dry-run (with or without --fix) previews; --fix alone applies.
            $dryRun = (bool) $this->option('dry-run');
            $messages = $this->fixer->apply($report, $dryRun);

            foreach ($messages as $message) {
                $this->line($message);
            }
        }

        $output = $this->render($report);
        $this->output->write($output);

        if ((bool) $this->option('html') && ! (bool) $this->option('json')) {
            $path = storage_path('wraith/report-'.date('YmdHis').'.html');
            $dir = dirname($path);

            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            file_put_contents($path, $output);
            $this->info('HTML report written to '.$path);
        }

        if ((bool) $this->option('ci')) {
            $threshold = (string) ($this->option('fail-on') ?: config('wraith.fail_on', 'high'));

            if (! Severity::isValid($threshold)) {
                $this->error('Invalid --fail-on value: '.$threshold);

                return 2;
            }

            if ($this->scorer->hasFailingSeverity($report, $threshold)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @param mixed $report
     */
    private function render($report): string
    {
        if ((bool) $this->option('json')) {
            return (new JsonReporter())->render($report);
        }

        if ((bool) $this->option('html')) {
            return (new HtmlReporter())->render($report);
        }

        if ((bool) $this->option('markdown')) {
            return (new MarkdownReporter())->render($report);
        }

        return (new TerminalReporter((bool) $this->option('score')))->render($report);
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
