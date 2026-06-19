<?php

namespace Sisly\Coach\Services;

use Illuminate\Support\Facades\Cache;
use Sisly\Coach\Models\CoachState;

/**
 * Manages CoachState persistence.
 *
 * Supports two drivers:
 *   - 'database' (default, recommended for production): uses Eloquent + the
 *     sisly_coach_states table. Durable and queryable.
 *   - 'cache': uses Laravel's cache (Redis/Memcached). Lower latency but
 *     state can be lost on cache eviction. Acceptable only for non-critical
 *     or dev environments.
 *
 * The driver is set via config('sisly-coach.state.driver').
 */
class CoachStateService
{
    public function __construct(
        private readonly string $driver,
        private readonly int    $ttlSeconds,
    ) {}

    /**
     * Load or create state for a session.
     */
    public function load(
        string $userId,
        string $sessionId,
        string $coachId,
        string $locale
    ): CoachState {
        if ($this->driver === 'cache') {
            return $this->loadFromCache($userId, $sessionId, $coachId, $locale);
        }

        return CoachState::loadOrCreate($userId, $sessionId, $coachId, $locale);
    }

    /**
     * Persist updated state after a turn.
     */
    public function save(CoachState $state): void
    {
        if ($this->driver === 'cache') {
            $this->saveToCache($state);
            return;
        }

        $state->save();
    }

    // -------------------------------------------------------------------------
    // Cache driver
    // -------------------------------------------------------------------------

    private function cacheKey(string $userId, string $sessionId, string $coachId): string
    {
        return "sisly_coach_state:{$userId}:{$sessionId}:{$coachId}";
    }

    private function loadFromCache(
        string $userId,
        string $sessionId,
        string $coachId,
        string $locale
    ): CoachState {
        $key  = $this->cacheKey($userId, $sessionId, $coachId);
        $data = Cache::get($key);

        if ($data) {
            $state = new CoachState($data);
            $state->exists = true;
            return $state;
        }

        // Fresh state
        $state            = new CoachState();
        $state->user_id   = $userId;
        $state->session_id = $sessionId;
        $state->coach_id  = $coachId;
        $state->locale    = $locale;
        $state->turn      = 0;
        $state->ended     = false;
        $state->last_2_messages = [];

        if (config('sisly-coach.cross_session_memory', false)) {
            // Best-effort cross-session memory from cache (may not be available)
            $prevKey = "sisly_coach_summary:{$userId}:{$coachId}";
            $state->situation_summary = Cache::get($prevKey);
        }

        return $state;
    }

    private function saveToCache(CoachState $state): void
    {
        $key = $this->cacheKey($state->user_id, $state->session_id, $state->coach_id);

        Cache::put($key, $state->toArray(), $this->ttlSeconds);

        if (config('sisly-coach.cross_session_memory', false) && $state->situation_summary) {
            $prevKey = "sisly_coach_summary:{$state->user_id}:{$state->coach_id}";
            Cache::put($prevKey, $state->situation_summary, $this->ttlSeconds * 30); // keep longer
        }
    }
}
