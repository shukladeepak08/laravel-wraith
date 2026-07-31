<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;
use SdPayHub\Wraith\Tests\TestCase;

final class FindingTest extends TestCase
{
    public function test_finding_to_array(): void
    {
        $finding = new Finding(
            Severity::HIGH,
            Category::SECURITY,
            'security.test',
            'Test description',
            'Why',
            'Fix it',
            'https://example.com',
            true,
            ['a' => 1]
        );

        $array = $finding->toArray();

        $this->assertSame('high', $array['severity']);
        $this->assertSame('security', $array['category']);
        $this->assertSame('security.test', $array['code']);
        $this->assertTrue($array['auto_fixable']);
        $this->assertSame(['a' => 1], $array['meta']);
    }

    public function test_invalid_severity_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Finding('nope', Category::SECURITY, 'x', 'd', 'w', 'f');
    }
}
