<?php

namespace Sisly\Coach\Tests\Feature;

use Mockery;
use Orchestra\Testbench\TestCase;
use Sisly\Coach\CoachServiceProvider;
use Sisly\Coach\Enums\SafetyVerdict;
use Sisly\Coach\Services\AnthropicService;
use Sisly\Coach\Services\CoachService;
use Sisly\Coach\Services\ContentLibraryService;
use Sisly\Coach\Services\CoachStateService;
use Sisly\Coach\Services\SafetyService;
use Sisly\Coach\Support\CoachResponse;
use Sisly\Coach\Support\ContentAsset;
use Sisly\Coach\Support\PrescriptionBlock;
use Sisly\Coach\Support\SafetyResult;

/**
 * Feature tests for POST /api/coach/message.
 * Tests the full HTTP layer including validation, auth resolution, and response shapes.
 * All AI calls are mocked — no real Anthropic calls in tests.
 */
class CoachMessageEndpointTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [CoachServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sisly-coach.anthropic.api_key', 'test-key');
        $app['config']->set('sisly-coach.state.driver', 'cache');
        $app['config']->set('sisly-coach.auth.user_id_param', 'sisly_user_id');
        $app['config']->set('sisly-coach.routing.enabled', true);
        $app['config']->set('sisly-coach.routing.prefix', 'api/coach');
        $app['config']->set('sisly-coach.routing.middleware', []);
        $app['config']->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockCoachService(CoachResponse $response): void
    {
        $mock = Mockery::mock(CoachService::class);
        $mock->shouldReceive('handle')->andReturn($response);
        $this->app->instance(CoachService::class, $mock);
    }

    private function normalResponse(): CoachResponse
    {
        return new CoachResponse(
            safety: new SafetyResult(SafetyVerdict::Ok, 'none', 'fine'),
            coachText: 'That sounds really tough. One step at a time.',
            prescription: null,
            asset: null,
            ended: false,
        );
    }

    // -------------------------------------------------------------------------
    // Validation tests
    // -------------------------------------------------------------------------

    public function test_missing_user_id_returns_401(): void
    {
        $response = $this->postJson('/api/coach/message', [
            'session_id'   => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'     => 'meetly',
            'locale'       => 'en',
            'user_message' => 'Hi',
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_coach_id_returns_422(): void
    {
        $this->mockCoachService($this->normalResponse());

        $response = $this->postJson('/api/coach/message', [
            'session_id'   => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'     => 'invalid_coach',
            'locale'       => 'en',
            'user_message' => 'Hi',
            'sisly_user_id' => 'user_abc123',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error', 'Validation failed.');
    }

    public function test_invalid_locale_returns_422(): void
    {
        $response = $this->postJson('/api/coach/message', [
            'session_id'    => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'      => 'meetly',
            'locale'        => 'fr',
            'user_message'  => 'Hi',
            'sisly_user_id' => 'user_abc123',
        ]);

        $response->assertStatus(422);
    }

    public function test_empty_message_returns_422(): void
    {
        $response = $this->postJson('/api/coach/message', [
            'session_id'    => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'      => 'meetly',
            'locale'        => 'en',
            'user_message'  => '',
            'sisly_user_id' => 'user_abc123',
        ]);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Normal turn response shape
    // -------------------------------------------------------------------------

    public function test_normal_turn_response_shape(): void
    {
        $this->mockCoachService($this->normalResponse());

        $response = $this->postJson('/api/coach/message', [
            'session_id'    => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'      => 'meetly',
            'locale'        => 'en',
            'user_message'  => 'Big presentation in 20 min, my hands are shaking',
            'sisly_user_id' => 'user_abc123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['safety', 'coach_text', 'prescription', 'asset', 'ended'])
                 ->assertJsonPath('safety.verdict', 'ok')
                 ->assertJsonPath('prescription', null)
                 ->assertJsonPath('ended', false);
    }

    // -------------------------------------------------------------------------
    // Handoff turn response shape
    // -------------------------------------------------------------------------

    public function test_handoff_turn_includes_prescription_and_asset(): void
    {
        $handoffResponse = new CoachResponse(
            safety: new SafetyResult(SafetyVerdict::Ok, 'none', 'fine'),
            coachText: 'Can I suggest something small? Here is what I am thinking.',
            prescription: new PrescriptionBlock(
                contentType: 'Meditation',
                currentMood: 'Anxious',
                targetMood:  'Calm',
                reason:      'A quiet two minutes before you walk in.',
            ),
            asset: new ContentAsset(
                contentId:      498,
                title:          'Rushing to start day',
                description:    'Rushing to start day',
                duration:       107,
                mediaCategory:  'Meditation',
                mediaType:      'Audio',
                mediaPath:      'https://sisly-eu-s3bucket.s3.eu-central-1.amazonaws.com/insights_Content/english/audio/insight_english_498_live.mp3',
                mediaThumbnail: 'https://sisly-eu-s3bucket.s3.eu-central-1.amazonaws.com/insights_Content/english/audio_thumbnails/insight_english_498_live.png',
            ),
            ended: false,
        );

        $this->mockCoachService($handoffResponse);

        $response = $this->postJson('/api/coach/message', [
            'session_id'    => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'      => 'meetly',
            'locale'        => 'en',
            'user_message'  => 'Still feeling tense',
            'sisly_user_id' => 'user_abc123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('safety.verdict', 'ok')
                 ->assertJsonPath('ended', false)
                 ->assertJsonStructure([
                     'prescription' => ['content_type', 'current_mood', 'target_mood', 'reason'],
                     'asset'        => ['content_id', 'title', 'media_type', 'media_path', 'media_thumbnail'],
                 ]);
    }

    // -------------------------------------------------------------------------
    // Crisis response (safety = flagged)
    // -------------------------------------------------------------------------

    public function test_crisis_response_shape_and_ends_chat(): void
    {
        $crisisResponse = new CoachResponse(
            safety: new SafetyResult(SafetyVerdict::Flagged, 'self_harm', 'explicit'),
            coachText: "I'm really glad you told me, and I'm a bit worried about you. You deserve real support right now, more than I can give. Please reach out: UAE HOPE line 800 4673, or 999 for emergencies. I'm here with you.",
            prescription: null,
            asset: null,
            ended: true,
        );

        $this->mockCoachService($crisisResponse);

        $response = $this->postJson('/api/coach/message', [
            'session_id'    => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'      => 'meetly',
            'locale'        => 'en',
            'user_message'  => "I don't want to be here anymore",
            'sisly_user_id' => 'user_abc123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('safety.verdict', 'flagged')
                 ->assertJsonPath('prescription', null)
                 ->assertJsonPath('asset', null)
                 ->assertJsonPath('ended', true);

        // Must contain crisis copy
        $data = $response->json();
        $this->assertStringContainsString('800 4673', $data['coach_text']);
    }

    // -------------------------------------------------------------------------
    // Checking verdict (badge = yellow, chat continues)
    // -------------------------------------------------------------------------

    public function test_checking_verdict_keeps_chat_open(): void
    {
        $checkingResponse = new CoachResponse(
            safety: new SafetyResult(SafetyVerdict::Checking, 'acute_distress', 'ambiguous'),
            coachText: 'That sounds really heavy. Can you tell me a bit more about what you mean?',
            prescription: null,
            asset: null,
            ended: false,
        );

        $this->mockCoachService($checkingResponse);

        $response = $this->postJson('/api/coach/message', [
            'session_id'    => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'coach_id'      => 'loopy',
            'locale'        => 'en',
            'user_message'  => "I can't do this anymore",
            'sisly_user_id' => 'user_abc123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('safety.verdict', 'checking')
                 ->assertJsonPath('ended', false);
    }

    // -------------------------------------------------------------------------
    // GET /coaches endpoint
    // -------------------------------------------------------------------------

    public function test_coaches_endpoint_returns_all_five(): void
    {
        $response = $this->getJson('/api/coach/coaches?locale=en');

        $response->assertStatus(200)
                 ->assertJsonCount(5, 'coaches')
                 ->assertJsonStructure([
                     'coaches' => [
                         '*' => ['id', 'name', 'emoji', 'color', 'spec', 'primed_opening'],
                     ],
                 ]);

        $ids = collect($response->json('coaches'))->pluck('id')->all();
        sort($ids);
        $this->assertSame(['boostly', 'loopy', 'meetly', 'presso', 'vento'], $ids);
    }

    public function test_coaches_endpoint_arabic_locale(): void
    {
        $response = $this->getJson('/api/coach/coaches?locale=ar');

        $response->assertStatus(200)
                 ->assertJsonCount(5, 'coaches');

        // Primed openings should contain Arabic text
        $openings = collect($response->json('coaches'))->pluck('primed_opening')->all();
        foreach ($openings as $opening) {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $opening);
        }
    }
}
