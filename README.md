# Sisly Coach — Laravel Package

AI-powered workplace mood coaching for Laravel applications. Five coaches (Meetly, Presso, Loopy, Boostly, Vento), powered by Claude, with EN/AR support and a parallel safety classifier that always overrides the coach.

---

## Installation

```bash
composer require sisly/coach
```

Publish the config:

```bash
php artisan vendor:publish --tag=sisly-coach-config
```

Run the migration:

```bash
php artisan migrate
```

Set your environment variables:

```env
ANTHROPIC_API_KEY=sk-ant-...
SISLY_CONTENT_API_URL=https://api.sisly.ai
SISLY_CRISIS_SIGNED_OFF=false   # Set to true only after clinical sign-off (see Hard Gates)
```

---

## Quick Start

The package auto-registers three routes:

| Method | URL                 | Purpose                                   |
| ------ | ------------------- | ----------------------------------------- |
| POST   | `api/coach/message` | One user message turn — the main endpoint |
| GET    | `api/coach/coaches` | Coach list + metadata for the picker UI   |
| GET    | `api/coach/health`  | Package health check                      |

### Step 1 — Set up auth middleware

The package reads the user ID from a request attribute, not `Auth::id()`. Your auth middleware must set it before the coach controller runs:

```php
// In your auth middleware:
$request->merge(['sisly_user_id' => $resolvedOpaqueUserId]);
```

Add your middleware to the package's route group in `config/sisly-coach.php`:

```php
'routing' => [
    'enabled'    => true,
    'prefix'     => 'api/coach',
    'middleware' => ['api', 'auth:sanctum'],  // add your auth here
],
```

The user ID param name is configurable:

```php
'auth' => [
    'user_id_param' => 'sisly_user_id',  // change if needed
],
```

### Step 2 — Client calls (Phase 1 — no backend call)

When a user picks a coach, display the primed opening client-side. **No backend call is made for the opening message.** Fetch the opening text from:

```
GET /api/coach/coaches?locale=en
```

Response:

```json
{
  "coaches": [
    {
      "id": "meetly",
      "name": "Meetly",
      "emoji": "📅",
      "color": "#FF9F35",
      "spec": "Calm before a meeting",
      "primed_opening": "Hi, I'm Meetly. Big meeting on your mind? Let's get you steady. Is it coming up, or did it just happen?"
    }
  ]
}
```

### Step 3 — User sends first message

From the first user message onwards, POST to `/api/coach/message`:

```json
{
  "session_id": "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11",
  "coach_id": "meetly",
  "locale": "en",
  "user_message": "Big presentation in 20 min, my hands are shaking"
}
```

**Normal turn response:**

```json
{
  "safety": { "verdict": "ok", "category": "none" },
  "coach_text": "That sounds really intense. Is it the content you're worried about, or the room?",
  "prescription": null,
  "asset": null,
  "ended": false
}
```

**Handoff turn response** (after 3–4 validation turns):

```json
{
  "safety": { "verdict": "ok", "category": "none" },
  "coach_text": "Can I suggest something small? Here's what I'm thinking — a quick meditation to ease that tension.",
  "prescription": {
    "content_type": "Meditation",
    "current_mood": "Anxious",
    "target_mood": "Calm",
    "reason": "A quiet two minutes before you walk in."
  },
  "asset": {
    "content_id": 498,
    "title": "Rushing to start day",
    "description": "Rushing to start day",
    "duration": 107,
    "media_category": "Meditation",
    "media_type": "Audio",
    "media_path": "https://sisly-eu-s3bucket.s3.eu-central-1.amazonaws.com/...",
    "media_thumbnail": "https://sisly-eu-s3bucket.s3.eu-central-1.amazonaws.com/..."
  },
  "ended": false
}
```

**Crisis response** (safety flagged — input must be disabled):

```json
{
  "safety": { "verdict": "flagged", "category": "self_harm" },
  "coach_text": "I'm really glad you told me, and I'm a bit worried about you. You deserve real support right now...",
  "prescription": null,
  "asset": null,
  "ended": true
}
```

When `ended: true`, disable the input field and show the crisis banner. Do not auto-reopen.

---

## Architecture

Every user message fires **two model calls in parallel**:

```
User message
     │
     ├─── Safety classifier (Haiku, cheap + fast) ──► SafetyResult
     │                                                      │
     └─── Coach reply (Sonnet, streamed) ─────────► CoachReply
                                                           │
                                            If flagged: discard coach ──► crisis response
                                            If ok/checking: parse prescription
                                                           │
                                            If prescription: fetch content asset (Sisly API)
                                                           │
                                            Update CoachState, return response
```

**Safety always overrides the coach.** The override is a literal `if` block in `CoachService`, not hidden in middleware.

### Coach → Content API mapping

| Coach   | `content_type` sent to Sisly API |
| ------- | -------------------------------- |
| Meetly  | `Meetings`                       |
| Presso  | `Too much`                       |
| Loopy   | `Quiet mind`                     |
| Boostly | `Confidence`                     |
| Vento   | `Let it out`                     |

### CoachState

One record per `(user_id, session_id, coach_id)`. Only the rolling `last_2_messages` and `situation_summary` (one line) are sent to the model — never the full transcript.

---

## Configuration Reference

Full config at `config/sisly-coach.php` after publishing.

| Key                        | Default                    | Description                   |
| -------------------------- | -------------------------- | ----------------------------- |
| `anthropic.api_key`        | `env('ANTHROPIC_API_KEY')` | Must be server-side only      |
| `anthropic.coach_model`    | `claude-sonnet-4-6`        | Coach reply model             |
| `anthropic.safety_model`   | `claude-haiku-4-5`         | Safety classifier model       |
| `state.driver`             | `database`                 | `database` or `cache`         |
| `state.ttl_seconds`        | `86400`                    | Session state TTL             |
| `routing.prefix`           | `api/coach`                | URL prefix                    |
| `routing.middleware`       | `['api']`                  | Add your auth here            |
| `auth.user_id_param`       | `sisly_user_id`            | Request attribute name        |
| `safety.crisis_signed_off` | `false`                    | HARD GATE — see below         |
| `cross_session_memory`     | `false`                    | Carry summary across sessions |

---

## Hard Gates — Do Not Ship to Real Users Until All Green

These are non-negotiable and copied from the product brief:

- [ ] **Qualified mental-health professional** has signed off on coach prompts AND `SAFETY_SYS` in **both EN and AR**
- [ ] **Verified UAE crisis helpline** number and routing copy locked in both languages.  
       The package ships with placeholder: UAE HOPE 800 4673 + 999. Replace via `SISLY_CRISIS_COPY_EN` and `SISLY_CRISIS_COPY_AR` env vars, then set `SISLY_CRISIS_SIGNED_OFF=true`
- [ ] **Arabic-language safety red-team** complete on Gulf dialect and code-switched samples
- [ ] **Native GCC Arabic copywriter** has authored personas and primed openings in Arabic (the Arabic strings in the package are Claude-drafted placeholders — NOT production copy)
- [ ] **Content library** has at least one asset per `content_type` per locale so prescriptions always resolve
- [ ] **Patent provisional + FTO** position confirmed with UAE/PCT attorney before public disclosure
- [ ] **Privacy review** confirms message content is never persisted beyond what's needed for the turn

Check status at any time:

```
GET /api/coach/health
```

---

## Telemetry / Privacy

The package logs only aggregate telemetry — never message content:

```php
Log::info('SislyCoach: Turn processed.', [
    'coach_id', 'locale', 'turn', 'verdict', 'had_prescription', 'content_type'
]);
```

The `user_id` in logs is the opaque ID you pass in — never an email or real name. This is a non-negotiable trust pillar from the product brief.

---

## Running Tests

```bash
composer test
# or
./vendor/bin/phpunit
```

Test cases cover all 10 scenarios from the developer execution guide:

| #   | Scenario                                        | Covered in                                      |
| --- | ----------------------------------------------- | ----------------------------------------------- |
| 1   | Primed opening — no backend call                | `CoachMessageEndpointTest`                      |
| 2   | Normal stress message — green badge             | `CoachMessageEndpointTest`                      |
| 3   | After 3–4 turns — prescription + content card   | `CoachMessageEndpointTest`                      |
| 4   | Post-card message — chat continues              | `CoachMessageEndpointTest`                      |
| 5   | Exhaustion idiom — NOT flagged                  | `SafetyServiceTest`                             |
| 6   | Explicit crisis — flagged, input disabled       | `SafetyServiceTest`, `CoachMessageEndpointTest` |
| 7   | Ambiguous message — checking badge              | `SafetyServiceTest`, `CoachMessageEndpointTest` |
| 8   | Long message (1,500+ chars)                     | validation in controller                        |
| 9   | Malformed safety response — fail closed         | `SafetyServiceTest`                             |
| 10  | Malformed prescription block — silently dropped | `PrescriptionParserTest`                        |

---

## License

Proprietary. © Sisly. All rights reserved.

Architecture, personas, GCC tuning, and classifier design are trade secrets.  
Patent provisional + FTO required before any public disclosure of method claims.

git add . && git commit -m 'update content categories - v1.0.6' && git tag v1.0.6 && git push origin main --tags


You can replace this text without touching any code — just set these env vars in your host app:

SISLY_CRISIS_COPY_EN="your clinically approved English copy"
SISLY_CRISIS_COPY_AR="your clinically approved Arabic copy"
SISLY_CRISIS_SIGNED_OFF=true

