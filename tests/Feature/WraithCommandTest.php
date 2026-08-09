<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
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
        // Avoid expectsOutputToContain() — added in Laravel 9; keep L8 compatible.
        $exit = Artisan::call('wraith', ['--score' => true, '--only' => 'application']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Overall score', Artisan::output());
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

    public function test_baseline_suppresses_findings_for_ci(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $path = storage_path('wraith/ci-baseline.json');
        config(['wraith.baseline.path' => $path]);
        @unlink($path);

        $this->artisan('wraith:baseline', ['--only' => 'application'])->assertExitCode(0);
        $this->assertFileExists($path);

        $this->artisan('wraith', [
            '--only' => 'application',
            '--ci' => true,
            '--fail-on' => 'critical',
            '--diff' => true,
        ])->assertExitCode(0);

        @unlink($path);
    }

    public function test_no_baseline_shows_issues_again(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $path = storage_path('wraith/ci-baseline-2.json');
        config(['wraith.baseline.path' => $path]);
        @unlink($path);

        $this->artisan('wraith:baseline', ['--only' => 'application'])->assertExitCode(0);

        $this->artisan('wraith', [
            '--only' => 'application',
            '--ci' => true,
            '--fail-on' => 'critical',
            '--no-baseline' => true,
        ])->assertExitCode(1);

        @unlink($path);
    }

    public function test_dynamic_blocked_outside_local(): void
    {
        config([
            'app.env' => 'production',
            'wraith.dynamic.require_local_env' => true,
        ]);

        $this->artisan('wraith', [
            '--only' => 'dynamic',
            '--dynamic' => true,
        ])->assertExitCode(2);
    }
}
