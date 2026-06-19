<?php

namespace Sisly\Coach\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Sisly\Coach\Enums\CoachId;
use Sisly\Coach\Enums\Locale;
use Sisly\Coach\Exceptions\AnthropicException;
use Sisly\Coach\Services\CoachService;

/**
 * CoachController — exposes a single endpoint: POST /coach/message
 *
 * Auth contract (from config sisly-coach.auth.user_id_param):
 *   The host app's auth middleware must set the opaque user ID on the request
 *   before this controller is reached. The package reads it as a request
 *   attribute — it never calls Auth::id(). This keeps it compatible with
 *   API-token, JWT, and any other auth pattern.
 *
 * Input (JSON body):
 *   session_id    — UUID, identifies the chat session
 *   coach_id      — one of: meetly, presso, loopy, boostly, vento
 *   locale        — 'en' or 'ar'
 *   user_message  — the raw message text (max 2,000 chars)
 *
 * Output: CoachResponse serialised as JSON (see Support\CoachResponse::toArray())
 *
 * Additionally exposes GET /coach/coaches — returns the list of coaches with
 * their metadata (name, emoji, colour, spec, primed opening) for the client
 * to render the coach picker without hardcoding anything.
 */
class CoachController extends Controller
{
    public function __construct(
        private readonly CoachService $coachService,
    ) {}

    /**
     * POST /coach/message
     * Handle one user message turn.
     */
    public function message(Request $request): JsonResponse
    {
        // ---------------------------------------------------------------------------
        // 1. Resolve user ID from the request (set by host app auth middleware)
        // ---------------------------------------------------------------------------
        $userIdParam = config('sisly-coach.auth.user_id_param', 'sisly_user_id');
        $userId      = $request->get($userIdParam)
            ?? $request->header('X-Sisly-User-Id');

        if (empty($userId)) {
            return response()->json([
                'error' => 'User identity not resolved. Ensure your auth middleware sets the ' .
                           "request attribute '{$userIdParam}' or the X-Sisly-User-Id header.",
            ], 401);
        }

        // ---------------------------------------------------------------------------
        // 2. Validate input
        // ---------------------------------------------------------------------------
        $maxLen    = config('sisly-coach.validation.max_message_length', 2000);
        $coachIds  = CoachId::values();
        $locales   = Locale::values();

        $validator = Validator::make($request->all(), [
            'session_id'   => ['required', 'string', 'uuid'],
            'coach_id'     => ['required', 'string', 'in:' . implode(',', $coachIds)],
            'locale'       => ['required', 'string', 'in:' . implode(',', $locales)],
            'user_message' => ['required', 'string', 'min:1', "max:{$maxLen}"],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'Validation failed.',
                'details' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // ---------------------------------------------------------------------------
        // 3. Delegate to CoachService
        // ---------------------------------------------------------------------------
        try {
            $coachResponse = $this->coachService->handle(
                userId:      (string) $userId,
                sessionId:   $validated['session_id'],
                coachId:     $validated['coach_id'],
                locale:      $validated['locale'],
                userMessage: $validated['user_message'],
            );
        } catch (AnthropicException $e) {
            Log::error('SislyCoach: Anthropic API error on /coach/message.', [
                'error'     => $e->getMessage(),
                'coach_id'  => $validated['coach_id'],
                'locale'    => $validated['locale'],
            ]);

            return response()->json([
                'error' => 'Coach service is temporarily unavailable. Please try again.',
            ], 503);
        } catch (\Throwable $e) {
            Log::error('SislyCoach: Unexpected error on /coach/message.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred.',
            ], 500);
        }

        return response()->json($coachResponse->toArray());
    }

    /**
     * GET /coach/coaches
     * Returns all coaches with their metadata for client-side coach picker rendering.
     * Also returns the primed opening per coach per locale — the client displays
     * this as the first message with NO backend call (Phase 1 of the method).
     */
    public function coaches(Request $request): JsonResponse
    {
        $locale = $request->query('locale', 'en');

        if (! in_array($locale, Locale::values(), true)) {
            $locale = 'en';
        }

        $coaches = [];

        foreach (CoachId::cases() as $coach) {
            $coaches[] = [
                'id'            => $coach->value,
                'name'          => $coach->label(),
                'emoji'         => $coach->emoji(),
                'color'         => $coach->color(),
                'spec'          => $coach->spec($locale),
                'primed_opening' => $coach->primedOpening($locale),
            ];
        }

        return response()->json(['coaches' => $coaches]);
    }

    /**
     * GET /coach/health
     * Simple health check — confirms the package is installed and configured.
     * Useful for monitoring and deployment verification.
     */
    public function health(): JsonResponse
    {
        $warnings = [];

        if (! config('sisly-coach.safety.crisis_signed_off', false)) {
            $warnings[] = 'HARD GATE: Crisis copy has not been signed off. ' .
                          'Set SISLY_CRISIS_SIGNED_OFF=true only after clinical review.';
        }

        if (empty(config('sisly-coach.anthropic.api_key'))) {
            $warnings[] = 'ANTHROPIC_API_KEY is not set.';
        }

        return response()->json([
            'status'   => empty($warnings) ? 'ok' : 'warnings',
            'package'  => 'sisly/coach',
            'warnings' => $warnings,
        ]);
    }
}
