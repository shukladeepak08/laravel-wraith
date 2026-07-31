<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;
use SdPayHub\Wraith\Scoring\Scorer;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;
use SdPayHub\Wraith\Tests\TestCase;

final class ScorerTest extends TestCase
{
    public function test_scoring_formula(): void
    {
        $finding = new Finding(
            Severity::HIGH,
            Category::SECURITY,
            'security.x',
            'd',
            'w',
            'f'
        );

        $result = new AnalysisResult('Security', Category::SECURITY, [$finding]);
        $report = new Report([$result]);

        $scorer = new Scorer(
            [Category::SECURITY => 2.0],
            [
                Severity::CRITICAL => 25,
                Severity::HIGH => 15,
                Severity::MEDIUM => 8,
                Severity::LOW => 3,
                Severity::INFO => 0,
            ]
        );

        $scored = $scorer->score($report);

        $this->assertSame(85.0, $scored->categoryScores()[Category::SECURITY]);
        $this->assertSame(85.0, $scored->overallScore());
        $this->assertTrue($scorer->hasFailingSeverity($scored, Severity::HIGH));
        $this->assertFalse($scorer->hasFailingSeverity($scored, Severity::CRITICAL));
    }
}
