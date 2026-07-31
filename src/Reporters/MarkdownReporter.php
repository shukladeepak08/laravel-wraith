<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Reporters;

use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;

final class MarkdownReporter implements Reporter
{
    public function render(Report $report): string
    {
        $lines = [];
        $lines[] = '# Wraith Report';
        $lines[] = '';
        $lines[] = sprintf('**Overall score:** %s / 100', $report->overallScore());
        $lines[] = '';

        if ($report->categoryScores() !== []) {
            $lines[] = '## Category scores';
            $lines[] = '';
            $lines[] = '| Category | Score |';
            $lines[] = '|---|---|';

            foreach ($report->categoryScores() as $category => $score) {
                $lines[] = sprintf('| %s | %s |', $category, $score);
            }

            $lines[] = '';
        }

        $findings = $report->findings();

        if ($findings === []) {
            $lines[] = 'No issues found.';
            $lines[] = '';

            return implode(PHP_EOL, $lines);
        }

        $lines[] = '## Findings';
        $lines[] = '';

        /** @var Finding $finding */
        foreach ($findings as $finding) {
            $lines[] = sprintf('### [%s] %s — %s', strtoupper($finding->severity()), $finding->code(), $finding->description());
            $lines[] = '';
            $lines[] = sprintf('- **Category:** %s', $finding->category());
            $lines[] = sprintf('- **Why it matters:** %s', $finding->whyItMatters());
            $lines[] = sprintf('- **Suggested fix:** %s', $finding->suggestedFix());
            $lines[] = sprintf('- **Auto-fixable:** %s', $finding->isAutoFixable() ? 'yes' : 'no');

            if ($finding->docUrl() !== null) {
                $lines[] = sprintf('- **Docs:** %s', $finding->docUrl());
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
