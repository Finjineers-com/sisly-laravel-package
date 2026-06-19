<?php

namespace Sisly\Coach\Support;

use Sisly\Coach\Enums\SafetyVerdict;

/**
 * Immutable value object returned by the SafetyService.
 */
readonly class SafetyResult
{
    public function __construct(
        public SafetyVerdict $verdict,
        public string        $category,
        public string        $rationale,
    ) {}

    public function toArray(): array
    {
        return [
            'verdict'   => $this->verdict->value,
            'category'  => $this->category,
        ];
    }
}
