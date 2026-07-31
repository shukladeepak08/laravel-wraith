<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Results;

use SdPayHub\Wraith\Support\Severity;

/**
 * Immutable finding DTO. No raw arrays passed around the pipeline.
 */
final class Finding
{
    /** @var string */
    private $severity;

    /** @var string */
    private $category;

    /** @var string */
    private $code;

    /** @var string */
    private $description;

    /** @var string */
    private $whyItMatters;

    /** @var string */
    private $suggestedFix;

    /** @var string|null */
    private $docUrl;

    /** @var bool */
    private $autoFixable;

    /** @var array<string, mixed> */
    private $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        string $severity,
        string $category,
        string $code,
        string $description,
        string $whyItMatters,
        string $suggestedFix,
        $docUrl = null,
        bool $autoFixable = false,
        array $meta = []
    ) {
        if (! Severity::isValid($severity)) {
            throw new \InvalidArgumentException('Invalid severity: '.$severity);
        }

        $this->severity = $severity;
        $this->category = $category;
        $this->code = $code;
        $this->description = $description;
        $this->whyItMatters = $whyItMatters;
        $this->suggestedFix = $suggestedFix;
        $this->docUrl = $docUrl;
        $this->autoFixable = $autoFixable;
        $this->meta = $meta;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function whyItMatters(): string
    {
        return $this->whyItMatters;
    }

    public function suggestedFix(): string
    {
        return $this->suggestedFix;
    }

    /**
     * @return string|null
     */
    public function docUrl()
    {
        return $this->docUrl;
    }

    public function isAutoFixable(): bool
    {
        return $this->autoFixable;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'category' => $this->category,
            'code' => $this->code,
            'description' => $this->description,
            'why_it_matters' => $this->whyItMatters,
            'suggested_fix' => $this->suggestedFix,
            'doc_url' => $this->docUrl,
            'auto_fixable' => $this->autoFixable,
            'meta' => $this->meta,
        ];
    }
}
