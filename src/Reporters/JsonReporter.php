<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Reporters;

use SdPayHub\Wraith\Results\Report;

final class JsonReporter implements Reporter
{
    public function render(Report $report): string
    {
        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json.PHP_EOL;
    }
}
