<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Contracts;

use SdPayHub\Wraith\Results\AnalysisResult;

/**
 * Opt-in dynamic analyzer — may make real HTTP requests to the app.
 * Only executed when --dynamic is passed.
 */
interface DynamicAnalyzer
{
    public function category(): string;

    public function name(): string;

    public function supports(): bool;

    public function analyze(): AnalysisResult;
}
