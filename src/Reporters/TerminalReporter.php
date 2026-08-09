<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Reporters;

use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;
use SdPayHub\Wraith\Support\Severity;

final class TerminalReporter implements Reporter
{
    /** @var bool */
    private $scoreOnly;

    public function __construct(bool $scoreOnly = false)
    {
        $this->scoreOnly = $scoreOnly;
    }

    public function render(Report $report): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = '  Wraith — Laravel diagnostic audit';
        $lines[] = '  ─────────────────────────────────';
        $lines[] = sprintf('  Overall score: %s / 100', $report->overallScore());
        $lines[] = '  (100 = healthy · lower = more / worse issues)';
        $lines[] = '';

        if ($report->categoryScores() !== []) {
            $lines[] = '  Category scores:';

            foreach ($report->categoryScores() as $category => $score) {
                $lines[] = sprintf('    %-16s %s', $category, $score);
            }

            $lines[] = '';
        }

        if ($this->scoreOnly) {
            $lines[] = '  Tip: run `php artisan wraith` (without --score) to see issues and fixes.';
            $lines[] = '';

            return implode(PHP_EOL, $lines).PHP_EOL;
        }

        $findings = $report->findings();

        if ($report->ignoredCount() > 0 || $report->baselinedCount() > 0) {
            $parts = [];

            if ($report->ignoredCount() > 0) {
                $parts[] = sprintf('%d ignored', $report->ignoredCount());
            }

            if ($report->baselinedCount() > 0) {
                $parts[] = sprintf('%d baselined', $report->baselinedCount());
            }

            $lines[] = '  Suppressed: '.implode(', ', $parts).' (see config ignore / baseline.json)';
            $lines[] = '';
        }

        $lines[] = '  How to read findings';
        $lines[] = '  • Severity: critical > high > medium > low > info';
        $lines[] = '  • Score impact: critical −25 · high −15 · medium −8 · low −3 · info −0';
        $lines[] = '  • Fix critical/high first; "Auto-fixable" can use --fix';
        $lines[] = '';

        if ($findings === []) {
            $lines[] = '  No issues found. Nice work.';
            $lines[] = '';

            return implode(PHP_EOL, $lines).PHP_EOL;
        }

        $grouped = [];

        foreach ($findings as $finding) {
            $grouped[$finding->severity()][] = $finding;
        }

        foreach (Severity::ORDER as $severity) {
            if (! isset($grouped[$severity])) {
                continue;
            }

            $lines[] = sprintf('  [%s] (%d)', strtoupper($severity), count($grouped[$severity]));

            /** @var Finding $finding */
            foreach ($grouped[$severity] as $finding) {
                $lines[] = sprintf('    • [%s] %s', $finding->code(), $finding->description());
                $lines[] = sprintf('      Why: %s', $finding->whyItMatters());
                $lines[] = sprintf('      Fix: %s', $finding->suggestedFix());

                if ($finding->isAutoFixable()) {
                    $lines[] = '      Auto-fixable: yes  →  php artisan wraith --fix --dry-run';
                }

                if ($finding->docUrl() !== null) {
                    $lines[] = sprintf('      Docs: %s', $finding->docUrl());
                }

                $lines[] = '';
            }
        }

        $lines[] = sprintf('  %d finding(s) in %.0f ms', count($findings), $report->durationMs());
        $lines[] = '';
        $lines[] = '  Next steps';
        $lines[] = '  • Preview safe fixes:  php artisan wraith --fix --dry-run';
        $lines[] = '  • Accept current debt: php artisan wraith:baseline';
        $lines[] = '  • HTML report:         php artisan wraith --html';
        $lines[] = '  • CI gate:             php artisan wraith --ci --fail-on=high';
        $lines[] = '  • Docs: https://github.com/shukladeepak08/laravel-wraith';
        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
