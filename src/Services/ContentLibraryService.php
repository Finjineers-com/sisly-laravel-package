<?php

namespace Sisly\Coach\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Sisly\Coach\Enums\CoachId;
use Sisly\Coach\Enums\Locale;
use Sisly\Coach\Support\ContentAsset;

/**
 * Fetches 2-minute content assets from the Sisly content library API.
 *
 * Endpoint: GET https://api.sisly.ai/api/v1/insights/by-type
 * Params:
 *   - content_type: coach-specific value (e.g. "Meetings" for Meetly)
 *   - local:        "english" or "arabic"
 *
 * Coach → content_type mapping (frozen — do not change without product sign-off):
 *   meetly  → Meetings
 *   presso  → Too much
 *   loopy   → Quiet mind
 *   boostly → Confidence
 *   vento   → Let it out
 *
 * Each item in the response has a nested "media" object with:
 *   media_type      → "Audio" or "Video"
 *   media_path      → URL to the media file
 *   media_thumbnail → URL to the thumbnail image
 *
 * A random asset is selected from the returned list to vary recommendations.
 * If the API is unreachable or returns no usable assets, null is returned
 * silently — the coach reply is still returned without a prescription card.
 */
class ContentLibraryService
{
    private Client $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $endpoint,
        private readonly int    $timeout,
    ) {
        $this->http = new Client([
            'base_uri'        => $this->baseUrl,
            'timeout'         => $this->timeout,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Resolve a prescription to a real content asset.
     *
     * @param  CoachId  $coach   Used to derive the content_type param
     * @param  Locale   $locale  Used to derive the local param
     * @return ContentAsset|null  Null if no asset found or API unreachable
     */
    public function resolve(CoachId $coach, Locale $locale): ?ContentAsset
    {
        $contentType = $coach->contentTypeParam();
        $localLabel  = $locale->apiLabel(); // 'english' or 'arabic'

        try {
            $response = $this->http->get($this->endpoint, [
                'query' => [
                    'content_type' => $contentType,
                    'local'        => $localLabel,
                ],
            ]);

            $items = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            Log::warning('SislyCoach: Content library API unreachable.', [
                'coach'        => $coach->value,
                'content_type' => $contentType,
                'locale'       => $localLabel,
                'error'        => $e->getMessage(),
            ]);
            return null;
        }

        if (! is_array($items) || empty($items)) {
            Log::warning('SislyCoach: Content library returned no assets.', [
                'coach'        => $coach->value,
                'content_type' => $contentType,
                'locale'       => $localLabel,
            ]);
            return null;
        }

        // Pick a random asset so each session gets variety
        $item = $items[array_rand($items)];

        return $this->hydrate($item);
    }

    /**
     * Map a raw API item to a typed ContentAsset value object.
     */
    private function hydrate(array $item): ?ContentAsset
    {
        $contentId = $item['content_id'] ?? null;
        if (! $contentId) {
            return null;
        }

        $media          = $item['media'] ?? [];
        $mediaType      = $media['media_type']      ?? null;
        $mediaPath      = $media['media_path']      ?? null;
        $mediaThumbnail = $media['media_thumbnail'] ?? null;

        return new ContentAsset(
            contentId:      (int) $contentId,
            title:          $item['title']          ?? '',
            description:    $item['description']    ?? '',
            duration:       (int) ($item['duration'] ?? 0),
            mediaCategory:  $item['media_category'] ?? '',
            mediaType:      $mediaType,
            mediaPath:      $mediaPath,
            mediaThumbnail: $mediaThumbnail,
        );
    }
}
