<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Pipeline;

use SdPayHub\Wraith\Baseline\BaselineStore;
use SdPayHub\Wraith\Baseline\FindingFilter;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Contracts\DynamicAnalyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Results\Report;
use SdPayHub\Wraith\Scoring\Scorer;

final class AnalyzerPipeline
{
    /** @var array<int, Analyzer> */
    private $analyzers;

    /** @var array<int, DynamicAnalyzer> */
    private $dynamicAnalyzers;

    /** @var Scorer */
    private $scorer;

    /**
     * @param array<int, Analyzer>         $analyzers
     * @param array<int, DynamicAnalyzer>  $dynamicAnalyzers
     */
    public function __construct(array $analyzers, array $dynamicAnalyzers, Scorer $scorer)
    {
        $this->analyzers = $analyzers;
        $this->dynamicAnalyzers = $dynamicAnalyzers;
        $this->scorer = $scorer;
    }

    /**
     * @param array<int, string> $only
     * @param array<int, string> $except
     */
    public function run(array $only = [], array $except = [], bool $includeDynamic = false, bool $applyBaseline = true): Report
    {
        $report = $this->runRaw($only, $except, $includeDynamic);
        $report = $this->filter($report, $applyBaseline);

        return $this->scorer->score($report);
    }

    /**
     * Unfiltered analysis (used when writing a baseline).
     *
     * @param array<int, string> $only
     * @param array<int, string> $except
     */
    public function runRaw(array $only = [], array $except = [], bool $includeDynamic = false): Report
    {
        $started = microtime(true);
        $results = [];

        foreach ($this->analyzers as $analyzer) {
            if (! $this->shouldRun($analyzer->category(), $only, $except)) {
                continue;
            }

            if (! $analyzer->supports()) {
                continue;
            }

            $results[] = $this->timedAnalyze($analyzer);
        }

        if ($includeDynamic) {
            foreach ($this->dynamicAnalyzers as $analyzer) {
                if (! $this->shouldRun($analyzer->category(), $only, $except)) {
                    continue;
                }

                if (! $analyzer->supports()) {
                    continue;
                }

                $results[] = $this->timedAnalyzeDynamic($analyzer);
            }
        }

        $durationMs = (microtime(true) - $started) * 1000;

        return new Report($results, [], 100.0, $durationMs);
    }

    private function filter(Report $report, bool $applyBaseline): Report
    {
        $ignore = (array) config('wraith.ignore', []);
        $fingerprints = [];

        if ($applyBaseline && (bool) config('wraith.baseline.enabled', true)) {
            $path = config('wraith.baseline.path');
            $path = is_string($path) && $path !== ''
                ? $path
                : storage_path('wraith/baseline.json');

            $store = new BaselineStore($path);
            $fingerprints = $store->fingerprints();
        }

        $filter = new FindingFilter($ignore, $fingerprints, $applyBaseline && $fingerprints !== []);

        return $filter->apply($report);
    }

    /**
     * @param array<int, string> $only
     * @param array<int, string> $except
     */
    private function shouldRun(string $category, array $only, array $except): bool
    {
        if ($only !== [] && ! in_array($category, $only, true)) {
            return false;
        }

        if ($except !== [] && in_array($category, $except, true)) {
            return false;
        }

        return true;
    }

    private function timedAnalyze(Analyzer $analyzer): AnalysisResult
    {
        $started = microtime(true);
        $result = $analyzer->analyze();
        $durationMs = (microtime(true) - $started) * 1000;

        return new AnalysisResult(
            $result->analyzer(),
            $result->category(),
            $result->findings(),
            $durationMs
        );
    }

    private function timedAnalyzeDynamic(DynamicAnalyzer $analyzer): AnalysisResult
    {
        $started = microtime(true);
        $result = $analyzer->analyze();
        $durationMs = (microtime(true) - $started) * 1000;

        return new AnalysisResult(
            $result->analyzer(),
            $result->category(),
            $result->findings(),
            $durationMs
        );
    }
}
