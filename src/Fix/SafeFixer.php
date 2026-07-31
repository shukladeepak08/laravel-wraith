<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Fix;

use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;
use Symfony\Component\Process\Process;

/**
 * Narrow, enumerable safe fixes only.
 * Every fix code must be documented in the README.
 */
final class SafeFixer
{
    /** @var string */
    private $backupPath;

    /** @var array<int, string> */
    private $enabled;

    /**
     * @param array<int, string> $enabled
     */
    public function __construct(string $backupPath, array $enabled)
    {
        $this->backupPath = $backupPath;
        $this->enabled = $enabled;
    }

    /**
     * @return array<int, string>
     */
    public function apply(Report $report, bool $dryRun = false): array
    {
        $messages = [];

        if (! $dryRun) {
            $this->createBackup();
        }

        foreach ($report->findings() as $finding) {
            if (! $finding->isAutoFixable()) {
                continue;
            }

            $fix = isset($finding->meta()['fix']) ? (string) $finding->meta()['fix'] : '';

            if ($fix === '' || ! in_array($fix, $this->enabled, true)) {
                continue;
            }

            $messages[] = $this->runFix($fix, $finding, $dryRun);
        }

        return array_values(array_filter($messages));
    }

    public function restore(): string
    {
        $latest = $this->latestBackupDir();

        if ($latest === null) {
            return 'No Wraith backup found to restore.';
        }

        $manifestFile = $latest.'/manifest.json';

        if (! is_file($manifestFile)) {
            return 'Backup manifest missing.';
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (! is_array($manifest)) {
            return 'Invalid backup manifest.';
        }

        foreach ($manifest as $relative => $content) {
            $target = base_path($relative);
            $dir = dirname($target);

            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            file_put_contents($target, $content);
        }

        return 'Restored from '.$latest;
    }

    private function createBackup(): void
    {
        $dir = rtrim($this->backupPath, '/\\').DIRECTORY_SEPARATOR.date('YmdHis');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $files = ['.gitignore', '.env', '.env.example'];
        $manifest = [];

        foreach ($files as $relative) {
            $path = base_path($relative);

            if (is_file($path)) {
                $manifest[$relative] = (string) file_get_contents($path);
            }
        }

        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        file_put_contents($this->backupPath.'/latest', $dir);
    }

    /**
     * @return string|null
     */
    private function latestBackupDir()
    {
        $pointer = rtrim($this->backupPath, '/\\').'/latest';

        if (is_file($pointer)) {
            $dir = trim((string) file_get_contents($pointer));

            return is_dir($dir) ? $dir : null;
        }

        return null;
    }

    private function runFix(string $fix, Finding $finding, bool $dryRun): string
    {
        if ($fix === 'gitignore_env') {
            return $this->fixGitignoreEnv($dryRun);
        }

        if ($fix === 'env_bool_normalize') {
            $key = isset($finding->meta()['key']) ? (string) $finding->meta()['key'] : '';
            $value = isset($finding->meta()['value']) ? (string) $finding->meta()['value'] : 'false';

            return $this->fixEnvBool($key, $value, $dryRun);
        }

        if ($fix === 'pint') {
            return $this->fixPint($dryRun);
        }

        return '';
    }

    private function fixGitignoreEnv(bool $dryRun): string
    {
        $path = base_path('.gitignore');

        if (! is_file($path)) {
            if ($dryRun) {
                return '[dry-run] Would create .gitignore with .env entry.';
            }

            file_put_contents($path, ".env\n");

            return 'Created .gitignore with .env entry.';
        }

        $contents = (string) file_get_contents($path);

        if (strpos($contents, '.env') !== false) {
            return '.env already present in .gitignore.';
        }

        if ($dryRun) {
            return '[dry-run] Would append .env to .gitignore.';
        }

        file_put_contents($path, rtrim($contents).PHP_EOL.'.env'.PHP_EOL);

        return 'Appended .env to .gitignore.';
    }

    private function fixEnvBool(string $key, string $value, bool $dryRun): string
    {
        if ($key === '') {
            return '';
        }

        $path = base_path('.env');

        if (! is_file($path)) {
            return '.env not found; skipped '.$key;
        }

        $contents = (string) file_get_contents($path);
        $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';
        $replacement = $key.'='.$value;

        if (preg_match($pattern, $contents) !== 1) {
            if ($dryRun) {
                return '[dry-run] Would append '.$replacement.' to .env';
            }

            file_put_contents($path, rtrim($contents).PHP_EOL.$replacement.PHP_EOL);

            return 'Appended '.$replacement.' to .env';
        }

        if ($dryRun) {
            return '[dry-run] Would set '.$replacement.' in .env';
        }

        $updated = preg_replace($pattern, $replacement, $contents, 1);
        file_put_contents($path, $updated);

        return 'Set '.$replacement.' in .env';
    }

    private function fixPint(bool $dryRun): string
    {
        $bin = base_path('vendor/bin/pint');

        if (! is_file($bin) && ! is_file($bin.'.bat')) {
            return 'Pint binary not found; skipped.';
        }

        if ($dryRun) {
            return '[dry-run] Would run vendor/bin/pint';
        }

        $cmd = is_file($bin.'.bat') ? $bin.'.bat' : $bin;
        $process = new Process([$cmd]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(180);
        $process->run();

        return $process->isSuccessful() ? 'Ran Pint successfully.' : 'Pint exited with errors.';
    }
}
