<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Reporters;

use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;

final class HtmlReporter implements Reporter
{
    public function render(Report $report): string
    {
        $findingsHtml = '';

        /** @var Finding $finding */
        foreach ($report->findings() as $finding) {
            $severity = htmlspecialchars($finding->severity(), ENT_QUOTES, 'UTF-8');
            $code = htmlspecialchars($finding->code(), ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars($finding->description(), ENT_QUOTES, 'UTF-8');
            $why = htmlspecialchars($finding->whyItMatters(), ENT_QUOTES, 'UTF-8');
            $fix = htmlspecialchars($finding->suggestedFix(), ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($finding->category(), ENT_QUOTES, 'UTF-8');

            $findingsHtml .= <<<HTML
            <article class="finding severity-{$severity}">
              <h3><span class="badge">{$severity}</span> {$code}</h3>
              <p class="desc">{$description}</p>
              <dl>
                <dt>Category</dt><dd>{$category}</dd>
                <dt>Why it matters</dt><dd>{$why}</dd>
                <dt>Suggested fix</dt><dd>{$fix}</dd>
              </dl>
            </article>
HTML;
        }

        if ($findingsHtml === '') {
            $findingsHtml = '<p class="clean">No issues found.</p>';
        }

        $scoresRows = '';

        foreach ($report->categoryScores() as $category => $score) {
            $scoresRows .= sprintf(
                '<tr><td>%s</td><td>%s</td></tr>',
                htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $score, ENT_QUOTES, 'UTF-8')
            );
        }

        $overall = htmlspecialchars((string) $report->overallScore(), ENT_QUOTES, 'UTF-8');
        $count = count($report->findings());

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Wraith Report</title>
  <style>
    :root { --bg:#0f1419; --panel:#1a2332; --text:#e7ecf3; --muted:#9aa7b8; --accent:#5eead4; }
    body { margin:0; font-family: "IBM Plex Sans", "Segoe UI", sans-serif; background: radial-gradient(1200px 600px at 10% -10%, #1e3a3a, var(--bg)); color: var(--text); }
    main { max-width: 880px; margin: 2rem auto; padding: 0 1.25rem 3rem; }
    h1 { font-weight: 600; letter-spacing: -0.02em; }
    .score { font-size: 2.5rem; color: var(--accent); }
    table { width:100%; border-collapse: collapse; margin: 1rem 0 2rem; background: var(--panel); }
    td, th { padding: .6rem .8rem; border-bottom: 1px solid #2a3648; text-align:left; }
    .finding { background: var(--panel); padding: 1rem 1.1rem; margin-bottom: .85rem; border-left: 3px solid #64748b; }
    .severity-critical { border-left-color: #f87171; }
    .severity-high { border-left-color: #fb923c; }
    .severity-medium { border-left-color: #fbbf24; }
    .severity-low { border-left-color: #60a5fa; }
    .severity-info { border-left-color: #94a3b8; }
    .badge { text-transform: uppercase; font-size: .7rem; letter-spacing: .06em; color: var(--muted); }
    dt { color: var(--muted); font-size: .8rem; margin-top: .5rem; }
    dd { margin: 0; }
    .meta { color: var(--muted); }
  </style>
</head>
<body>
  <main>
    <h1>Wraith</h1>
    <p class="meta">Point-in-time Laravel diagnostic audit</p>
    <p class="score">{$overall} <span style="font-size:1rem;color:var(--muted)">/ 100</span></p>
    <p class="meta">100 = healthy. Issues subtract points: critical −25, high −15, medium −8, low −3, info −0. Fix critical/high first.</p>
    <table>
      <thead><tr><th>Category</th><th>Score</th></tr></thead>
      <tbody>{$scoresRows}</tbody>
    </table>
    <h2>Findings ({$count})</h2>
    {$findingsHtml}
  </main>
</body>
</html>
HTML;
    }
}
