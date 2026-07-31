<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Support\Severity;
use SdPayHub\Wraith\Tests\TestCase;

final class SeverityTest extends TestCase
{
    public function test_meets_threshold(): void
    {
        $this->assertTrue(Severity::meetsThreshold(Severity::CRITICAL, Severity::HIGH));
        $this->assertTrue(Severity::meetsThreshold(Severity::HIGH, Severity::HIGH));
        $this->assertFalse(Severity::meetsThreshold(Severity::LOW, Severity::HIGH));
    }
}
