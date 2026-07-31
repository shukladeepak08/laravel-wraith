<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\CodeQuality;

use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;
use Symfony\Component\Process\Process;

/**
 * Wraps PHPStan/Larastan and Pint — does not reimplement static analysis.
 */
final class CodeQualityAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::CODE_QUALITY;
    }

    public function name(): string
    {
        return 'Code Quality';
    }

    public function supports(): bool
    {
        return true;
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];
        $findings = array_merge($findings, $this->runPhpStan());
        $findings = array_merge($findings, $this->checkPint());

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function runPhpStan(): array
    {
        $base = base_path();
        $bin = (string) config('wraith.tools.phpstan', 'vendor/bin/phpstan');
        $path = $this->resolveBin($base, $bin);

        if ($path === null) {
            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'code_quality.phpstan_missing',
                    'PHPStan/Larastan is not installed; static analysis skipped.',
                    'Reliability checks overlapping Enlightn\'s dead-code/invalid-call findings are best delegated to PHPStan.',
                    'Install larastan/larastan or phpstan/phpstan as a dev dependency.'
                ),
            ];
        }

        try {
            $process = new Process([$path, 'analyse', '--error-format=json', '--no-progress', 'app']);
            $process->setWorkingDirectory($base);
            $process->setTimeout(300);
            $process->run();
        } catch (\Throwable $e) {
            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'code_quality.phpstan_failed',
                    'PHPStan failed to run: '.$e->getMessage(),
                    'Static analysis output could not be aggregated.',
                    'Run PHPStan manually and fix configuration issues.'
                ),
            ];
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            $output = trim($process->getErrorOutput());
        }

        $data = json_decode($output, true);

        if (! is_array($data) || ! isset($data['files']) || ! is_array($data['files'])) {
            if ($process->isSuccessful()) {
                return [];
            }

            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'code_quality.phpstan_unparsed',
                    'PHPStan ran but JSON output could not be parsed.',
                    'Wraith aggregates PHPStan findings rather than reimplementing them.',
                    'Run `vendor/bin/phpstan analyse` manually.'
                ),
            ];
        }

        $findings = [];
        $count = 0;

        foreach ($data['files'] as $file => $info) {
            if (! is_array($info) || ! isset($info['messages']) || ! is_array($info['messages'])) {
                continue;
            }

            foreach ($info['messages'] as $message) {
                $count++;
                $line = isset($message['line']) ? (int) $message['line'] : 0;
                $text = isset($message['message']) ? (string) $message['message'] : 'Issue';
                $findings[] = $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'code_quality.phpstan',
                    sprintf('%s:%d — %s', basename((string) $file), $line, $text),
                    'PHPStan/Larastan reported a static analysis issue.',
                    'Fix the reported issue or baseline it intentionally in PHPStan config.',
                    null,
                    false,
                    ['file' => $file, 'line' => $line]
                );

                if ($count >= 25) {
                    break 2;
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkPint(): array
    {
        $base = base_path();
        $bin = (string) config('wraith.tools.pint', 'vendor/bin/pint');
        $path = $this->resolveBin($base, $bin);

        if ($path === null) {
            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'code_quality.pint_missing',
                    'Laravel Pint is not installed; style check skipped.',
                    'Consistent formatting reduces review noise; Pint is the Laravel-standard tool.',
                    'Install laravel/pint as a dev dependency.',
                    null,
                    false
                ),
            ];
        }

        try {
            $process = new Process([$path, '--test', '--format=json']);
            $process->setWorkingDirectory($base);
            $process->setTimeout(180);
            $process->run();
        } catch (\Throwable $e) {
            return [];
        }

        if ($process->isSuccessful()) {
            return [];
        }

        return [
            $this->finding(
                Severity::LOW,
                $this->category(),
                'code_quality.pint_dirty',
                'Laravel Pint reports formatting issues.',
                'Inconsistent style slows reviews and hides meaningful diffs.',
                'Run `vendor/bin/pint` to fix style automatically.',
                null,
                true,
                ['fix' => 'pint']
            ),
        ];
    }

    /**
     * @return string|null
     */
    private function resolveBin(string $base, string $bin)
    {
        if (is_file($bin)) {
            return $bin;
        }

        $candidate = $base.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $bin);

        if (is_file($candidate)) {
            return $candidate;
        }

        // Windows .bat
        if (is_file($candidate.'.bat')) {
            return $candidate.'.bat';
        }

        return null;
    }
}
