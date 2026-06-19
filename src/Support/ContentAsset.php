<?php

namespace Sisly\Coach\Support;

/**
 * Immutable value object representing a resolved 2-minute content asset
 * fetched from the Sisly content library API.
 *
 * Shape mirrors the API response:
 * {
 *   "content_id": 505,
 *   "title": "Feeling stuck",
 *   "description": "Feeling stuck",
 *   "duration": 108,
 *   "media_category": "Sound",
 *   "media": {
 *     "media_type": "Audio",
 *     "media_path": "https://...",
 *     "media_thumbnail": "https://..."
 *   }
 * }
 *
 * media_type is "Audio" or "Video" — the client uses this to decide which player to render.
 */
readonly class ContentAsset
{
    public function __construct(
        public int     $contentId,
        public string  $title,
        public string  $description,
        public int     $duration,        // seconds
        public string  $mediaCategory,
        public ?string $mediaType,       // "Audio" or "Video"
        public ?string $mediaPath,
        public ?string $mediaThumbnail,
    ) {}

    public function toArray(): array
    {
        return [
            'content_id'      => $this->contentId,
            'title'           => $this->title,
            'description'     => $this->description,
            'duration'        => $this->duration,
            'media_category'  => $this->mediaCategory,
            'media_type'      => $this->mediaType,
            'media_path'      => $this->mediaPath,
            'media_thumbnail' => $this->mediaThumbnail,
        ];
    }
}
