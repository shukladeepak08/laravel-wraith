<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Feature;

use SdPayHub\Wraith\Tests\TestCase;

final class WraithCommandTest extends TestCase
{
    public function test_wraith_command_runs(): void
    {
        $this->artisan('wraith')
            ->assertExitCode(0);
    }

    public function test_wraith_json_output(): void
    {
        $this->artisan('wraith', ['--json' => true, '--only' => 'application'])
            ->assertExitCode(0);
    }

    public function test_wraith_score_only(): void
    {
        $this->artisan('wraith', ['--score' => true, '--only' => 'application'])
            ->expectsOutputToContain('Overall score')
            ->assertExitCode(0);
    }

    public function test_ci_fail_on_threshold(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $this->artisan('wraith', [
            '--only' => 'application',
            '--ci' => true,
            '--fail-on' => 'critical',
        ])->assertExitCode(1);
    }
}
