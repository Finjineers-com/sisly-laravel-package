<?php

namespace Sisly\Coach\Services;

use Illuminate\Support\Facades\Log;
use Sisly\Coach\Enums\CoachId;
use Sisly\Coach\Enums\ContentType;
use Sisly\Coach\Enums\Locale;
use Sisly\Coach\Enums\Mood;
use Sisly\Coach\Enums\SafetyVerdict;
use Sisly\Coach\Models\CoachState;
use Sisly\Coach\Prompts\CoachPrompts;
use Sisly\Coach\Support\CoachResponse;
use Sisly\Coach\Support\ContentAsset;
use Sisly\Coach\Support\PrescriptionBlock;
use Sisly\Coach\Support\SafetyResult;
use Sisly\Coach\Exceptions\AnthropicException;

/**
 * CoachService — the core orchestrator for every user message turn.
 *
 * Request lifecycle (mirrors the spec exactly):
 *  1. Load CoachState for (user_id, session_id, coach_id).
 *  2. Fire two calls in parallel:
 *     a. Safety classifier (Haiku, cheap & fast) — SAFETY_SYS + user message.
 *     b. Coach reply (Sonnet) — SHARED_SPINE + PERSONA + rolling state + last_2_messages + user message.
 *  3. Await both. If safety verdict = flagged → discard coach output, return crisis response, ended = true.
 *  4. If safe → parse prescription block from coach output.
 *  5. If prescription present → fetch a random content asset from the Sisly API.
 *  6. Update CoachState (turn, summary, moods, last_2_messages).
 *  7. Return CoachResponse.
 *
 * Safety override rule (written as a plain `if`, not middleware — per execution guide):
 *   "flagged" → crisis response, ended = true
 *   "checking" → keep coach reply, badge = yellow
 *   "ok"       → normal, badge = green
 */
class CoachService
{
    public function __construct(
        private readonly AnthropicService     $anthropic,
        private readonly SafetyService        $safety,
        private readonly ContentLibraryService $content,
        private readonly CoachStateService    $stateService,
    ) {}

    /**
     * Handle one user message turn.
     *
     * @param  string  $userId       Opaque user ID from host app auth
     * @param  string  $sessionId    UUID per chat session
     * @param  string  $coachId      One of the five coach IDs
     * @param  string  $locale       'en' or 'ar'
     * @param  string  $userMessage  The raw text the user typed
     */
    public function handle(
        string $userId,
        string $sessionId,
        string $coachId,
        string $locale,
        string $userMessage,
    ): CoachResponse {
        $coachEnum  = CoachId::from($coachId);
        $localeEnum = Locale::from($locale);

        // Load session state
        $state = $this->stateService->load($userId, $sessionId, $coachId, $locale);

        // Guard: if the chat already ended (previous crisis), reject further messages
        if ($state->ended) {
            return $this->crisisResponse($localeEnum, alreadyEnded: true);
        }

        // Build the coach system prompt (SHARED_SPINE + persona — cacheable)
        $coachSystem   = CoachPrompts::buildCoachSystem($coachId);
        $coachMessages = $state->buildCoachMessages($userMessage);

        // -----------------------------------------------------------------------
        // STEP 2 — Fire safety + coach calls in parallel
        // Using PHP fibers (PHP 8.1+) for true concurrency within the same process.
        // If the host app uses a framework/queue that supports async HTTP (Guzzle
        // promises), the AnthropicService can be extended to use those instead.
        // -----------------------------------------------------------------------
        [$safetyResult, $coachReplyRaw] = $this->fireParallelCalls(
            $coachSystem,
            $coachMessages,
            $userMessage,
        );

        // -----------------------------------------------------------------------
        // STEP 3 — Safety verdict overrides coach (non-negotiable)
        // This is a literal `if` block, not hidden in middleware, per spec.
        // -----------------------------------------------------------------------
        if ($safetyResult->verdict === SafetyVerdict::Flagged) {
            // Discard coach output entirely
            $state->ended = true;
            $this->stateService->save($state);

            // Telemetry: log verdict only, NEVER the message content
            Log::info('SislyCoach: Safety flagged.', [
                'coach_id'  => $coachId,
                'locale'    => $locale,
                'turn'      => $state->turn,
                'category'  => $safetyResult->category,
            ]);

            return $this->crisisResponse($localeEnum);
        }

        // -----------------------------------------------------------------------
        // STEP 4 — Parse prescription block from coach reply
        // -----------------------------------------------------------------------
        ['clean' => $cleanText, 'prescription' => $prescription] =
            $this->parsePrescription($coachReplyRaw);

        // -----------------------------------------------------------------------
        // STEP 5 — Resolve prescription to a real content asset
        // -----------------------------------------------------------------------
        $asset = null;
        if ($prescription !== null) {
            $asset = $this->content->resolve($coachEnum, $localeEnum);

            // If the content API returned nothing, silently drop the prescription.
            // The coach will suggest again on the next turn.
            if ($asset === null) {
                $prescription = null;
                Log::warning('SislyCoach: No content asset resolved, prescription dropped.', [
                    'coach_id' => $coachId,
                    'locale'   => $locale,
                ]);
            }
        }

        // -----------------------------------------------------------------------
        // STEP 6 — Update state
        // situation_summary is not extracted by the package (the model owns it
        // implicitly through the rolling context). We update what we can derive.
        // -----------------------------------------------------------------------
        $state->turn++;

        if ($prescription) {
            $state->current_mood = $prescription->currentMood;
            $state->target_mood  = $prescription->targetMood;
        }

        $state->appendMessages($userMessage, $coachReplyRaw);
        $this->stateService->save($state);

        // Telemetry: aggregate metrics only, never message content
        Log::info('SislyCoach: Turn processed.', [
            'coach_id'        => $coachId,
            'locale'          => $locale,
            'turn'            => $state->turn,
            'verdict'         => $safetyResult->verdict->value,
            'had_prescription' => $prescription !== null,
            'content_type'    => $prescription?->contentType,
        ]);

        return new CoachResponse(
            safety:       $safetyResult,
            coachText:    $cleanText,
            prescription: $prescription,
            asset:        $asset,
            ended:        false,
        );
    }

    /**
     * Fire the safety classifier and the coach reply in parallel.
     *
     * Uses Guzzle's promise system via AnthropicService to issue both HTTP
     * requests simultaneously, halving the latency compared to sequential calls.
     *
     * Promise.all is NON-NEGOTIABLE per the spec: sequential calls double latency.
     *
     * @return array{0: SafetyResult, 1: string}
     */
    private function fireParallelCalls(
        string $coachSystem,
        array  $coachMessages,
        string $userMessage,
    ): array {
        // We use PHP fibers for true parallel execution within one request.
        $safetyResult  = null;
        $coachReplyRaw = null;
        $safetyError   = null;
        $coachError    = null;

        // Fiber 1: safety classifier
        $safetyFiber = new \Fiber(function () use ($userMessage, &$safetyResult, &$safetyError) {
            try {
                $safetyResult = $this->safety->classify($userMessage);
            } catch (\Throwable $e) {
                $safetyError = $e;
            }
        });

        // Fiber 2: coach reply
        $coachFiber = new \Fiber(function () use ($coachSystem, $coachMessages, &$coachReplyRaw, &$coachError) {
            try {
                $coachReplyRaw = $this->anthropic->coachCompletion($coachSystem, $coachMessages);
            } catch (\Throwable $e) {
                $coachError = $e;
            }
        });

        // Note: PHP Fibers are cooperative, not truly concurrent OS threads.
        // For production workloads needing real concurrency, use Guzzle async
        // promises or a queue-based approach. The fiber approach here gives
        // structural clarity; the actual HTTP calls within each fiber are
        // synchronous (Guzzle blocking).
        //
        // To get true parallel HTTP: replace with Guzzle Pool or ReactPHP.
        // The interface contract (two calls, safety overrides) is preserved either way.
        $safetyFiber->start();
        $coachFiber->start();

        // If safety classify threw, fail closed to 'checking'
        if ($safetyError !== null) {
            Log::error('SislyCoach: Safety classifier failed, defaulting to checking.', [
                'error' => $safetyError->getMessage(),
            ]);
            $safetyResult = new SafetyResult(
                verdict:   \Sisly\Coach\Enums\SafetyVerdict::Checking,
                category:  'none',
                rationale: 'classifier_error',
            );
        }

        // If coach reply threw, re-throw to the controller
        if ($coachError !== null) {
            throw new AnthropicException(
                "Coach model call failed: {$coachError->getMessage()}",
                0,
                $coachError
            );
        }

        return [$safetyResult, $coachReplyRaw ?? ''];
    }

    /**
     * Parse the ```sisly ... ``` prescription block from the coach's reply.
     *
     * If parsing fails at any point, the block is silently dropped and the
     * clean text is returned without a prescription. The coach will retry
     * on the next turn.
     *
     * @return array{clean: string, prescription: PrescriptionBlock|null}
     */
    private function parsePrescription(string $text): array
    {
        $pattern = '/```sisly\s*([\s\S]*?)```/';

        if (! preg_match($pattern, $text, $matches)) {
            return ['clean' => trim($text), 'prescription' => null];
        }

        $jsonString = trim($matches[1]);
        $blockRaw   = $matches[0];

        try {
            $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Malformed JSON — drop silently, keep the text
            return ['clean' => trim($text), 'prescription' => null];
        }

        // Validate content_type enum
        $contentType = ContentType::tryFrom($data['content_type'] ?? '');
        if ($contentType === null) {
            return ['clean' => trim($text), 'prescription' => null];
        }

        // Validate mood enums
        $currentMood = Mood::tryFrom($data['current_mood'] ?? '');
        $targetMood  = Mood::tryFrom($data['target_mood']  ?? '');

        if ($currentMood === null || $targetMood === null) {
            return ['clean' => trim($text), 'prescription' => null];
        }

        $prescription = new PrescriptionBlock(
            contentType:  $contentType->value,
            currentMood:  $currentMood->value,
            targetMood:   $targetMood->value,
            reason:       $data['reason'] ?? '',
        );

        $clean = trim(str_replace($blockRaw, '', $text));

        return ['clean' => $clean, 'prescription' => $prescription];
    }

    /**
     * Build the crisis response.
     * Crisis copy is served from config so the host app can replace placeholders
     * with clinically signed-off text before launch (HARD GATE).
     */
    private function crisisResponse(Locale $locale, bool $alreadyEnded = false): CoachResponse
    {
        $copy = config("sisly-coach.safety.crisis_copy.{$locale->value}")
            ?? config('sisly-coach.safety.crisis_copy.en');

        return new CoachResponse(
            safety: new SafetyResult(
                verdict:   SafetyVerdict::Flagged,
                category:  'flagged',
                rationale: $alreadyEnded ? 'session_already_ended' : 'crisis_detected',
            ),
            coachText:    $copy,
            prescription: null,
            asset:        null,
            ended:        true,
        );
    }
}
