<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Results;

/**
 * Aggregated report across all analyzer runs.
 */
final class Report
{
    /** @var array<int, AnalysisResult> */
    private $results;

    /** @var array<string, float> */
    private $categoryScores;

    /** @var float */
    private $overallScore;

    /** @var float */
    private $durationMs;

    /**
     * @param array<int, AnalysisResult> $results
     * @param array<string, float>       $categoryScores
     */
    public function __construct(
        array $results,
        array $categoryScores = [],
        float $overallScore = 100.0,
        float $durationMs = 0.0
    ) {
        $this->results = array_values($results);
        $this->categoryScores = $categoryScores;
        $this->overallScore = $overallScore;
        $this->durationMs = $durationMs;
    }

    /**
     * @return array<int, AnalysisResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @return array<int, Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->results as $result) {
            foreach ($result->findings() as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @return array<string, float>
     */
    public function categoryScores(): array
    {
        return $this->categoryScores;
    }

    public function overallScore(): float
    {
        return $this->overallScore;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function withScores(array $categoryScores, float $overallScore): self
    {
        return new self($this->results, $categoryScores, $overallScore, $this->durationMs);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'overall_score' => $this->overallScore,
            'category_scores' => $this->categoryScores,
            'duration_ms' => $this->durationMs,
            'finding_count' => count($this->findings()),
            'results' => array_map(static function (AnalysisResult $result) {
                return $result->toArray();
            }, $this->results),
        ];
    }
}
