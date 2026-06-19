<?php

namespace Sisly\Coach\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Sisly\Coach\Enums\CoachId;
use Sisly\Coach\Enums\Locale;
use Sisly\Coach\Enums\Mood;

/**
 * Sisly CoachState — rolling session state per (user_id, session_id, coach_id).
 *
 * The one-line situation_summary IS the memory sent to the model.
 * last_2_messages is a small rolling window — NOT the full transcript.
 * Message content is never logged in analytics (privacy pillar).
 *
 * @property string      $user_id            Opaque user identifier from host app auth
 * @property string      $session_id         UUID per chat session
 * @property string      $coach_id           One of the five CoachId enum values
 * @property string      $locale             'en' or 'ar'
 * @property int         $turn               Increments on every user message
 * @property string|null $situation_summary  One-line running summary (the model's memory)
 * @property string|null $current_mood       One of the 5 Mood enum values
 * @property string|null $target_mood        One of the 5 Mood enum values
 * @property array       $last_2_messages    Rolling [{role, content}, ...] window (max 2 pairs)
 * @property bool        $ended              True only when safety verdict is flagged
 */
class CoachState extends Model
{
    protected $table = 'sisly_coach_states';

    protected $fillable = [
        'user_id',
        'session_id',
        'coach_id',
        'locale',
        'turn',
        'situation_summary',
        'current_mood',
        'target_mood',
        'last_2_messages',
        'ended',
    ];

    protected $casts = [
        'turn'             => 'integer',
        'last_2_messages'  => 'array',
        'ended'            => 'boolean',
    ];

    protected $attributes = [
        'turn'             => 0,
        'situation_summary' => null,
        'current_mood'     => null,
        'target_mood'      => null,
        'last_2_messages'  => '[]',
        'ended'            => false,
    ];

    /**
     * Find or initialise state for a given (user_id, session_id, coach_id, locale) tuple.
     * On first message for this session, a fresh record is created.
     * Cross-session memory behaviour is controlled by config('sisly-coach.cross_session_memory').
     */
    public static function loadOrCreate(
        string $userId,
        string $sessionId,
        string $coachId,
        string $locale
    ): self {
        $state = static::firstOrNew([
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'coach_id'   => $coachId,
        ]);

        if (! $state->exists) {
            $state->locale = $locale;

            // Cross-session memory: carry over situation_summary from previous session
            // if the feature is enabled, otherwise start fresh.
            if (config('sisly-coach.cross_session_memory', false)) {
                $previous = static::where('user_id', $userId)
                    ->where('coach_id', $coachId)
                    ->whereNotNull('situation_summary')
                    ->orderByDesc('updated_at')
                    ->value('situation_summary');

                $state->situation_summary = $previous;
            }
        }

        return $state;
    }

    /**
     * Append a message pair (user + assistant) to the rolling last_2_messages window.
     * Keeps only the last 2 full pairs (4 messages) to stay token-lean.
     */
    public function appendMessages(string $userMessage, string $assistantReply): void
    {
        $window   = $this->last_2_messages ?? [];
        $window[] = ['role' => 'user',      'content' => $userMessage];
        $window[] = ['role' => 'assistant', 'content' => $assistantReply];

        // Trim to last 4 items = 2 pairs
        if (count($window) > 4) {
            $window = array_slice($window, -4);
        }

        $this->last_2_messages = array_values($window);
    }

    /**
     * Build the messages array sent to the coach model.
     * Format: [state context message] + rolling window + new user message.
     */
    public function buildCoachMessages(string $newUserMessage): array
    {
        $messages = [];

        // Prepend a compact state context message so the model has memory
        // without needing the full transcript.
        $contextParts = [];

        if ($this->turn > 0 && $this->situation_summary) {
            $contextParts[] = "Summary so far: {$this->situation_summary}";
        }
        if ($this->current_mood) {
            $contextParts[] = "Current mood: {$this->current_mood}";
        }
        if ($this->target_mood) {
            $contextParts[] = "Target mood: {$this->target_mood}";
        }

        if (! empty($contextParts)) {
            $messages[] = [
                'role'    => 'user',
                'content' => '[Context: ' . implode(' | ', $contextParts) . ']',
            ];
            $messages[] = [
                'role'    => 'assistant',
                'content' => 'Understood.',
            ];
        }

        // Append the rolling last_2_messages window
        foreach (($this->last_2_messages ?? []) as $msg) {
            $messages[] = $msg;
        }

        // Finally append the new user message
        $messages[] = ['role' => 'user', 'content' => $newUserMessage];

        return $messages;
    }
}
