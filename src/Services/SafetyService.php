<?php

namespace Sisly\Coach\Services;

use Sisly\Coach\Enums\SafetyVerdict;
use Sisly\Coach\Prompts\CoachPrompts;
use Sisly\Coach\Support\SafetyResult;

/**
 * Handles the independent safety classifier call.
 *
 * The safety verdict ALWAYS overrides the coach — this is non-negotiable.
 * The override logic is a plain `if` block in CoachService, not hidden in middleware,
 * as specified in the developer execution guide.
 *
 * Fail-closed: if the safety response cannot be parsed, the verdict defaults to
 * 'checking' (cautious, not crash).
 */
class SafetyService
{
    public function __construct(
        private readonly AnthropicService $anthropic,
    ) {}

    /**
     * Run the safety classifier on the user's message.
     * This runs in parallel with the coach call via concurrent promises.
     */
    public function classify(string $userMessage): SafetyResult
    {
        $raw = $this->anthropic->safetyCompletion(
            systemPrompt: CoachPrompts::SAFETY_SYS,
            messages:     [['role' => 'user', 'content' => $userMessage]],
        );

        return $this->parse($raw);
    }

    /**
     * Parse the raw model output into a SafetyResult.
     *
     * Fail-closed: any parse failure → 'checking'.
     * This ensures a malformed safety response never silently passes as 'ok'.
     */
    private function parse(string $raw): SafetyResult
    {
        $clean = trim(preg_replace('/```json|```/i', '', $raw));

        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new SafetyResult(
                verdict:   SafetyVerdict::Checking,
                category:  'none',
                rationale: 'parse_failed',
            );
        }

        $verdictStr = $decoded['verdict'] ?? '';
        $verdict    = SafetyVerdict::tryFrom($verdictStr) ?? SafetyVerdict::Checking;

        return new SafetyResult(
            verdict:   $verdict,
            category:  $decoded['category']  ?? 'none',
            rationale: $decoded['rationale'] ?? '',
        );
    }
}
