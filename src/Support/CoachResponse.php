<?php

namespace Sisly\Coach\Support;

/**
 * Immutable value object representing the full response for one coach turn.
 *
 * This is the internal representation. The HTTP layer serialises it to JSON
 * using toArray(), which matches the frozen API contract from the spec:
 *
 * Normal turn:
 * {
 *   "safety":       { "verdict": "ok|checking", "category": "none|..." },
 *   "coach_text":   "1–3 sentence reply",
 *   "prescription": null,
 *   "asset":        null,
 *   "ended":        false
 * }
 *
 * Handoff turn:
 * {
 *   "safety":       { "verdict": "ok", "category": "none" },
 *   "coach_text":   "warm summary line",
 *   "prescription": { "content_type": "...", "current_mood": "...", "target_mood": "...", "reason": "..." },
 *   "asset":        { "content_id": 497, "title": "...", "media_type": "Audio", "media_path": "...", "media_thumbnail": "...", ... },
 *   "ended":        false
 * }
 *
 * Crisis:
 * {
 *   "safety":       { "verdict": "flagged", "category": "self_harm|..." },
 *   "coach_text":   "<clinically signed-off crisis copy in user's locale>",
 *   "prescription": null,
 *   "asset":        null,
 *   "ended":        true
 * }
 */
readonly class CoachResponse
{
    public function __construct(
        public SafetyResult       $safety,
        public string             $coachText,
        public ?PrescriptionBlock $prescription,
        public ?ContentAsset      $asset,
        public bool               $ended,
    ) {}

    public function toArray(): array
    {
        return [
            'safety'       => $this->safety->toArray(),
            'coach_text'   => $this->coachText,
            'prescription' => $this->prescription?->toArray(),
            'asset'        => $this->asset?->toArray(),
            'ended'        => $this->ended,
        ];
    }
}
