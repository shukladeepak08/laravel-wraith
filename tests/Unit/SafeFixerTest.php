<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Fix\SafeFixer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;
use SdPayHub\Wraith\Tests\TestCase;

final class SafeFixerTest extends TestCase
{
    public function test_dry_run_env_bool_normalize(): void
    {
        $envPath = base_path('.env');
        file_put_contents($envPath, "APP_DEBUG=maybe\n");

        $finding = new Finding(
            Severity::MEDIUM,
            Category::CONFIGURATION,
            'config.invalid_bool_env',
            'd',
            'w',
            'f',
            null,
            true,
            ['fix' => 'env_bool_normalize', 'key' => 'APP_DEBUG', 'value' => 'false']
        );

        $report = new Report([
            new AnalysisResult('Configuration', Category::CONFIGURATION, [$finding]),
        ]);

        $fixer = new SafeFixer(storage_path('wraith/backups-test'), ['env_bool_normalize']);
        $messages = $fixer->apply($report, true);

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('dry-run', $messages[0]);
        $this->assertStringContainsString('APP_DEBUG=maybe', (string) file_get_contents($envPath));
    }
}
