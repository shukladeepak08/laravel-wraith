<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Reporters;

use SdPayHub\Wraith\Results\Report;

interface Reporter
{
    public function render(Report $report): string;
}
