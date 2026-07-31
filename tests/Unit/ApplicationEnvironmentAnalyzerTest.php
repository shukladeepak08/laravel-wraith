<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Analyzers\Application\ApplicationEnvironmentAnalyzer;
use SdPayHub\Wraith\Support\Severity;
use SdPayHub\Wraith\Tests\TestCase;

final class ApplicationEnvironmentAnalyzerTest extends TestCase
{
    public function test_flags_debug_in_production(): void
    {
        config(['app.env' => 'production', 'app.debug' => true, 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $analyzer = new ApplicationEnvironmentAnalyzer();
        $result = $analyzer->analyze();
        $codes = array_map(static function ($f) {
            return $f->code();
        }, $result->findings());

        $this->assertContains('app.debug_in_production', $codes);

        foreach ($result->findings() as $finding) {
            if ($finding->code() === 'app.debug_in_production') {
                $this->assertSame(Severity::CRITICAL, $finding->severity());
            }
        }
    }

    public function test_flags_missing_app_key(): void
    {
        config(['app.env' => 'testing', 'app.debug' => false, 'app.key' => '']);

        $analyzer = new ApplicationEnvironmentAnalyzer();
        $result = $analyzer->analyze();
        $codes = array_map(static function ($f) {
            return $f->code();
        }, $result->findings());

        $this->assertContains('app.key_missing', $codes);
    }
}
