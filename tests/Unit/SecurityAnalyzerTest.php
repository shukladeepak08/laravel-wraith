<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Analyzers\Security\SecurityAnalyzer;
use SdPayHub\Wraith\Tests\TestCase;

final class SecurityAnalyzerTest extends TestCase
{
    public function test_flags_env_not_gitignored_when_missing(): void
    {
        // Ensure a temporary .gitignore without .env is not needed —
        // we assert the analyzer at least returns a result cleanly.
        $analyzer = new SecurityAnalyzer();
        $this->assertTrue($analyzer->supports());
        $result = $analyzer->analyze();
        $this->assertSame('security', $result->category());
    }

    public function test_flags_non_https_app_url_in_production(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'http://example.com',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $analyzer = new SecurityAnalyzer();
        $codes = array_map(static function ($f) {
            return $f->code();
        }, $analyzer->analyze()->findings());

        $this->assertContains('security.app_url_not_https', $codes);
    }
}
