<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Baseline;

use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;

/**
 * Drop findings matching config ignore codes or a baseline file.
 */
final class FindingFilter
{
    /** @var array<int, string> */
    private $ignoreCodes;

    /** @var array<string, true> */
    private $baselineFingerprints;

    /** @var bool */
    private $applyBaseline;

    /**
     * @param array<int, string>   $ignoreCodes
     * @param array<string, true>  $baselineFingerprints
     */
    public function __construct(array $ignoreCodes = [], array $baselineFingerprints = [], bool $applyBaseline = true)
    {
        $this->ignoreCodes = array_values(array_filter(array_map('strval', $ignoreCodes)));
        $this->baselineFingerprints = $baselineFingerprints;
        $this->applyBaseline = $applyBaseline;
    }

    public function apply(Report $report): Report
    {
        $ignored = 0;
        $baselined = 0;
        $results = [];

        foreach ($report->results() as $result) {
            $kept = [];

            foreach ($result->findings() as $finding) {
                if ($this->isIgnored($finding)) {
                    $ignored++;
                    continue;
                }

                if ($this->applyBaseline && $this->isBaselined($finding)) {
                    $baselined++;
                    continue;
                }

                $kept[] = $finding;
            }

            $results[] = new AnalysisResult(
                $result->analyzer(),
                $result->category(),
                $kept,
                $result->durationMs()
            );
        }

        return new Report(
            $results,
            $report->categoryScores(),
            $report->overallScore(),
            $report->durationMs(),
            $ignored,
            $baselined
        );
    }

    private function isIgnored(Finding $finding): bool
    {
        return in_array($finding->code(), $this->ignoreCodes, true);
    }

    private function isBaselined(Finding $finding): bool
    {
        if ($this->baselineFingerprints === []) {
            return false;
        }

        return isset($this->baselineFingerprints[FindingFingerprint::for($finding)]);
    }
}
