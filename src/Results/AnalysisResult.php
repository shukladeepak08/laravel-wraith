<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Results;

/**
 * Result of a single analyzer run.
 */
final class AnalysisResult
{
    /** @var string */
    private $analyzer;

    /** @var string */
    private $category;

    /** @var array<int, Finding> */
    private $findings;

    /** @var float */
    private $durationMs;

    /**
     * @param array<int, Finding> $findings
     */
    public function __construct(
        string $analyzer,
        string $category,
        array $findings = [],
        float $durationMs = 0.0
    ) {
        $this->analyzer = $analyzer;
        $this->category = $category;
        $this->findings = array_values($findings);
        $this->durationMs = $durationMs;
    }

    public function analyzer(): string
    {
        return $this->analyzer;
    }

    public function category(): string
    {
        return $this->category;
    }

    /**
     * @return array<int, Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function isClean(): bool
    {
        return count($this->findings) === 0;
    }

    public function count(): int
    {
        return count($this->findings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'analyzer' => $this->analyzer,
            'category' => $this->category,
            'duration_ms' => $this->durationMs,
            'findings' => array_map(static function (Finding $finding) {
                return $finding->toArray();
            }, $this->findings),
        ];
    }
}
