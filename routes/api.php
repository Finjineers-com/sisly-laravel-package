<?php

use Illuminate\Support\Facades\Route;
use Sisly\Coach\Http\Controllers\CoachController;

/*
|--------------------------------------------------------------------------
| Sisly Coach Package Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the CoachServiceProvider when routing.enabled
| is true. The prefix and middleware are configurable via sisly-coach.php.
|
| Default prefix:  api/coach
| Default routes:
|   POST api/coach/message        — one user message turn (the main endpoint)
|   GET  api/coach/coaches        — coach list + metadata for the picker UI
|   GET  api/coach/health         — package health check
|
| Auth: the host app MUST add its own auth middleware to the
| 'sisly-coach.routing.middleware' config array, or wrap these routes
| in its own middleware groups. The package intentionally ships with only
| the 'api' middleware group and no auth — auth is the host app's concern.
|
*/

Route::prefix(config('sisly-coach.routing.prefix', 'api/coach'))
    ->middleware(config('sisly-coach.routing.middleware', ['api']))
    ->group(function () {

        // The only coach route — processes one user message turn.
        // Auth: host app middleware must resolve the user_id before this is called.
        Route::post('/message', [CoachController::class, 'message'])
            ->name('sisly.coach.message');

        // Coach picker metadata — safe to call without auth if the UI is public.
        // Returns: id, name, emoji, color, spec, primed_opening per locale.
        Route::get('/coaches', [CoachController::class, 'coaches'])
            ->name('sisly.coach.coaches');

        // Health check — deployment and monitoring verification.
        Route::get('/health', [CoachController::class, 'health'])
            ->name('sisly.coach.health');
    });
