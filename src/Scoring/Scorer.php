<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Scoring;

use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;
use SdPayHub\Wraith\Support\Severity;

/**
 * Transparent, configurable scoring.
 *
 * Formula:
 *   category_score = max(0, 100 - sum(penalty(severity) for findings in category))
 *   overall_score  = sum(category_score * weight) / sum(weights of categories present)
 *
 * Weights and penalties live in config/wraith.php.
 */
final class Scorer
{
    /** @var array<string, float> */
    private $weights;

    /** @var array<string, int> */
    private $penalties;

    /**
     * @param array<string, float> $weights
     * @param array<string, int>   $penalties
     */
    public function __construct(array $weights, array $penalties)
    {
        $this->weights = $weights;
        $this->penalties = $penalties;
    }

    public function score(Report $report): Report
    {
        $byCategory = [];

        foreach ($report->findings() as $finding) {
            $category = $finding->category();

            if (! isset($byCategory[$category])) {
                $byCategory[$category] = [];
            }

            $byCategory[$category][] = $finding;
        }

        // Include clean categories from results so they score 100.
        foreach ($report->results() as $result) {
            if (! isset($byCategory[$result->category()])) {
                $byCategory[$result->category()] = [];
            }
        }

        $categoryScores = [];
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($byCategory as $category => $findings) {
            $score = 100.0;

            /** @var Finding $finding */
            foreach ($findings as $finding) {
                $penalty = isset($this->penalties[$finding->severity()])
                    ? (int) $this->penalties[$finding->severity()]
                    : 0;
                $score -= $penalty;
            }

            $score = max(0.0, $score);
            $categoryScores[$category] = $score;

            $weight = isset($this->weights[$category]) ? (float) $this->weights[$category] : 1.0;
            $weightedSum += $score * $weight;
            $weightTotal += $weight;
        }

        $overall = $weightTotal > 0.0 ? round($weightedSum / $weightTotal, 1) : 100.0;

        return $report->withScores($categoryScores, $overall);
    }

    public function hasFailingSeverity(Report $report, string $threshold): bool
    {
        if (! Severity::isValid($threshold)) {
            return false;
        }

        foreach ($report->findings() as $finding) {
            if (Severity::meetsThreshold($finding->severity(), $threshold)) {
                return true;
            }
        }

        return false;
    }
}
