<?php

namespace Sisly\Coach\Support;

/**
 * Immutable value object representing a resolved 2-minute content asset
 * fetched from the Sisly content library API.
 *
 * Shape mirrors the API response documented in the project handover:
 * {
 *   "content_id": 497,
 *   "title": "Stressful mornings",
 *   "description": "Stressful mornings",
 *   "duration": 109,
 *   "media_category": "Do with me",
 *   "media": {
 *     "audio_path": "https://...",
 *     "audio_thumbnail": "https://..."
 *   }
 * }
 */
readonly class ContentAsset
{
    public function __construct(
        public int     $contentId,
        public string  $title,
        public string  $description,
        public int     $duration,        // seconds
        public string  $mediaCategory,
        public ?string $audioPath,
        public ?string $thumbnail,
    ) {}

    public function toArray(): array
    {
        return [
            'content_id'     => $this->contentId,
            'title'          => $this->title,
            'description'    => $this->description,
            'duration'       => $this->duration,
            'media_category' => $this->mediaCategory,
            'audio_path'     => $this->audioPath,
            'thumbnail'      => $this->thumbnail,
        ];
    }
}
