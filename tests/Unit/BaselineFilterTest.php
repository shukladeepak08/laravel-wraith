<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Baseline\BaselineStore;
use SdPayHub\Wraith\Baseline\FindingFilter;
use SdPayHub\Wraith\Baseline\FindingFingerprint;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;
use SdPayHub\Wraith\Support\Severity;
use SdPayHub\Wraith\Tests\TestCase;

final class BaselineFilterTest extends TestCase
{
    public function test_ignore_codes_drop_findings(): void
    {
        $report = $this->sampleReport([
            $this->finding('app.debug_in_production'),
            $this->finding('app.key_missing'),
        ]);

        $filtered = (new FindingFilter(['app.debug_in_production']))->apply($report);

        $this->assertCount(1, $filtered->findings());
        $this->assertSame(1, $filtered->ignoredCount());
        $this->assertSame('app.key_missing', $filtered->findings()[0]->code());
    }

    public function test_baseline_store_round_trip(): void
    {
        $path = storage_path('wraith/test-baseline.json');
        @unlink($path);

        $finding = $this->finding('app.key_missing');
        $report = $this->sampleReport([$finding]);

        $store = new BaselineStore($path);
        $this->assertSame(1, $store->write($report));
        $this->assertTrue($store->exists());

        $fps = $store->fingerprints();
        $this->assertArrayHasKey(FindingFingerprint::for($finding), $fps);

        $filtered = (new FindingFilter([], $fps, true))->apply($report);
        $this->assertCount(0, $filtered->findings());
        $this->assertSame(1, $filtered->baselinedCount());

        @unlink($path);
    }

    /**
     * @param array<int, Finding> $findings
     */
    private function sampleReport(array $findings): Report
    {
        return new Report([
            new AnalysisResult('test', 'application', $findings),
        ]);
    }

    private function finding(string $code): Finding
    {
        return new Finding(
            Severity::HIGH,
            'application',
            $code,
            'Description for '.$code,
            'Why',
            'Fix'
        );
    }
}
