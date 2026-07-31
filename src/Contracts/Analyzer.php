<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Contracts;

use SdPayHub\Wraith\Results\AnalysisResult;

/**
 * Static, side-effect-free analyzer contract.
 */
interface Analyzer
{
    /**
     * Category key used by --only / --except (e.g. "security").
     */
    public function category(): string;

    /**
     * Human-readable analyzer name.
     */
    public function name(): string;

    /**
     * Whether this analyzer can run in the current environment.
     */
    public function supports(): bool;

    /**
     * Run analysis and return findings.
     */
    public function analyze(): AnalysisResult;
}
