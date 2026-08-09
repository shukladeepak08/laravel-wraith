<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Baseline;

use SdPayHub\Wraith\Results\Finding;
use SdPayHub\Wraith\Results\Report;

/**
 * Persist and load a JSON baseline of accepted findings.
 */
final class BaselineStore
{
    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return array<string, true> fingerprint => true
     */
    public function fingerprints(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $raw = file_get_contents($this->path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['findings']) || ! is_array($decoded['findings'])) {
            return [];
        }

        $map = [];

        foreach ($decoded['findings'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $fp = isset($entry['fingerprint']) ? (string) $entry['fingerprint'] : '';

            if ($fp !== '') {
                $map[$fp] = true;
            }
        }

        return $map;
    }

    public function write(Report $report): int
    {
        $entries = [];

        foreach ($report->findings() as $finding) {
            $entries[] = $this->entry($finding);
        }

        usort($entries, static function (array $a, array $b) {
            return strcmp($a['code'].$a['fingerprint'], $b['code'].$b['fingerprint']);
        });

        $payload = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'finding_count' => count($entries),
            'findings' => $entries,
        ];

        $dir = dirname($this->path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        return count($entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Finding $finding): array
    {
        return [
            'fingerprint' => FindingFingerprint::for($finding),
            'code' => $finding->code(),
            'category' => $finding->category(),
            'severity' => $finding->severity(),
            'description' => $finding->description(),
        ];
    }
}
