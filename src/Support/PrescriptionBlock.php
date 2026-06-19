<?php

namespace Sisly\Coach\Support;

/**
 * Immutable value object representing a parsed ```sisly``` prescription block.
 * Keys and enum values stay in English — they are a machine contract.
 * Only `reason` is in the user's language.
 */
readonly class PrescriptionBlock
{
    public function __construct(
        public string $contentType,   // Meditation|DoWithMe|Affirmation|Sound|Podcast
        public string $currentMood,   // Excited|Happy|Calm|Anxious|Sad
        public string $targetMood,    // Excited|Happy|Calm|Anxious|Sad
        public string $reason,        // One warm line in the user's language
    ) {}

    public function toArray(): array
    {
        return [
            'content_type'  => $this->contentType,
            'current_mood'  => $this->currentMood,
            'target_mood'   => $this->targetMood,
            'reason'        => $this->reason,
        ];
    }
}
