<?php

namespace Sisly\Coach\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sisly\Coach\Services\CoachService;
use Sisly\Coach\Services\AnthropicService;
use Sisly\Coach\Services\SafetyService;
use Sisly\Coach\Services\ContentLibraryService;
use Sisly\Coach\Services\CoachStateService;
use ReflectionClass;
use Mockery;

/**
 * Tests for CoachService::parsePrescription (via reflection — it's private).
 * The prescription parser is critical: a malformed block must NEVER crash
 * the turn. Silently drop and continue is the correct behaviour.
 */
class PrescriptionParserTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeService(): CoachService
    {
        return new CoachService(
            Mockery::mock(AnthropicService::class),
            Mockery::mock(SafetyService::class),
            Mockery::mock(ContentLibraryService::class),
            Mockery::mock(CoachStateService::class),
        );
    }

    private function callParsePrescription(CoachService $service, string $text): array
    {
        $ref    = new ReflectionClass($service);
        $method = $ref->getMethod('parsePrescription');
        $method->setAccessible(true);
        return $method->invoke($service, $text);
    }

    public function test_valid_prescription_parsed(): void
    {
        $text = <<<TEXT
        Can I suggest something small? Here's what I'm thinking — a quick meditation to help ease that pre-meeting tension.
        ```sisly
        { "content_type": "Meditation", "current_mood": "Anxious", "target_mood": "Calm", "reason": "A quiet two minutes before you walk in." }
        ```
        TEXT;

        $service = $this->makeService();
        $result  = $this->callParsePrescription($service, $text);

        $this->assertNotNull($result['prescription']);
        $this->assertSame('Meditation',  $result['prescription']->contentType);
        $this->assertSame('Anxious',     $result['prescription']->currentMood);
        $this->assertSame('Calm',        $result['prescription']->targetMood);
        $this->assertSame('A quiet two minutes before you walk in.', $result['prescription']->reason);
        $this->assertStringNotContainsString('```sisly', $result['clean']);
    }

    public function test_no_prescription_block_returns_null(): void
    {
        $text    = "That sounds really tough. One step at a time.";
        $service = $this->makeService();
        $result  = $this->callParsePrescription($service, $text);

        $this->assertNull($result['prescription']);
        $this->assertSame($text, $result['clean']);
    }

    public function test_malformed_json_drops_silently(): void
    {
        $text = "Here's something.\n```sisly\n{ bad json }\n```";
        $service = $this->makeService();
        $result  = $this->callParsePrescription($service, $text);

        $this->assertNull($result['prescription']);
        // Text should still be returned cleanly
        $this->assertStringContainsString("Here's something.", $result['clean']);
    }

    public function test_invalid_content_type_enum_drops_silently(): void
    {
        $text = <<<TEXT
        Something warm.
        ```sisly
        { "content_type": "InvalidType", "current_mood": "Anxious", "target_mood": "Calm", "reason": "test" }
        ```
        TEXT;

        $service = $this->makeService();
        $result  = $this->callParsePrescription($service, $text);

        $this->assertNull($result['prescription']);
    }

    public function test_invalid_mood_enum_drops_silently(): void
    {
        $text = <<<TEXT
        Something warm.
        ```sisly
        { "content_type": "Meditation", "current_mood": "Nervous", "target_mood": "Calm", "reason": "test" }
        ```
        TEXT;

        $service = $this->makeService();
        $result  = $this->callParsePrescription($service, $text);

        $this->assertNull($result['prescription']);
    }

    public function test_all_valid_content_types_parsed(): void
    {
        $types   = ['Meditation', 'DoWithMe', 'Affirmation', 'Sound', 'Podcast'];
        $service = $this->makeService();

        foreach ($types as $type) {
            $text = <<<TEXT
            Warm words.
            ```sisly
            { "content_type": "{$type}", "current_mood": "Sad", "target_mood": "Happy", "reason": "test" }
            ```
            TEXT;

            $result = $this->callParsePrescription($service, $text);
            $this->assertNotNull($result['prescription'], "Failed for content_type: {$type}");
            $this->assertSame($type, $result['prescription']->contentType);
        }
    }

    public function test_all_valid_moods_parsed(): void
    {
        $moods   = ['Excited', 'Happy', 'Calm', 'Anxious', 'Sad'];
        $service = $this->makeService();

        foreach ($moods as $mood) {
            $text = <<<TEXT
            Warm words.
            ```sisly
            { "content_type": "Meditation", "current_mood": "{$mood}", "target_mood": "Calm", "reason": "test" }
            ```
            TEXT;

            $result = $this->callParsePrescription($service, $text);
            $this->assertNotNull($result['prescription'], "Failed for mood: {$mood}");
        }
    }
}
