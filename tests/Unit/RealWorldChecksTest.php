<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Tests\Unit;

use SdPayHub\Wraith\Analyzers\Security\SecurityAnalyzer;
use SdPayHub\Wraith\Analyzers\Performance\PerformanceAssetsAnalyzer;
use SdPayHub\Wraith\Analyzers\Configuration\ConfigurationAnalyzer;
use SdPayHub\Wraith\Tests\TestCase;

final class RealWorldChecksTest extends TestCase
{
    public function test_flags_cors_wildcard_with_credentials(): void
    {
        config([
            'cors.allowed_origins' => ['*'],
            'cors.supports_credentials' => true,
        ]);

        // Ensure cors.php path check passes by writing a temp file if missing
        $cors = config_path('cors.php');
        if (! is_file($cors)) {
            if (! is_dir(dirname($cors))) {
                mkdir(dirname($cors), 0775, true);
            }
            file_put_contents($cors, "<?php\nreturn [];\n");
        }

        $codes = $this->codes((new SecurityAnalyzer())->analyze()->findings());
        $this->assertContains('security.cors_star_with_credentials', $codes);
    }

    public function test_flags_mail_log_in_production(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'mail.default' => 'log',
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'session.driver' => 'redis',
        ]);

        $codes = $this->codes((new PerformanceAssetsAnalyzer())->analyze()->findings());
        $this->assertContains('performance.mail_driver_non_delivery', $codes);
    }

    public function test_env_example_drift_detection(): void
    {
        $example = base_path('.env.example');
        file_put_contents($example, "APP_NAME=Laravel\nAPP_KEY=\nAPP_URL=\n");

        $configFile = config_path('services.php');
        if (! is_dir(dirname($configFile))) {
            mkdir(dirname($configFile), 0775, true);
        }
        file_put_contents($configFile, "<?php\nreturn ['x' => env('STRIPE_SECRET', null)];\n");

        $codes = $this->codes((new ConfigurationAnalyzer())->analyze()->findings());
        $this->assertContains('config.env_example_drift', $codes);
    }

    /**
     * @param array<int, \SdPayHub\Wraith\Results\Finding> $findings
     * @return array<int, string>
     */
    private function codes(array $findings): array
    {
        return array_map(static function ($f) {
            return $f->code();
        }, $findings);
    }
}
