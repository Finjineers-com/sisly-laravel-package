<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sisly_coach_states', function (Blueprint $table) {
            $table->id();

            // Opaque user identifier from the host app's auth layer.
            // Never an email or real name — privacy pillar.
            $table->string('user_id', 128)->index();

            // UUID generated per chat session by the client.
            $table->string('session_id', 36);

            // One of: meetly, presso, loopy, boostly, vento
            $table->string('coach_id', 20);

            // 'en' or 'ar'
            $table->string('locale', 5)->default('en');

            // Increments on every user message turn.
            $table->unsignedSmallInteger('turn')->default(0);

            // One-line running summary — the model's memory. Never the full transcript.
            $table->text('situation_summary')->nullable();

            // Detected mood states (Excited|Happy|Calm|Anxious|Sad)
            $table->string('current_mood', 20)->nullable();
            $table->string('target_mood', 20)->nullable();

            // Rolling window of last 2 message pairs (max 4 messages).
            // Message content is NOT logged to analytics — only kept here for
            // in-turn context. Privacy pillar: personal data never shared.
            $table->json('last_2_messages')->nullable();

            // True only when safety verdict is 'flagged'. Chat is closed.
            $table->boolean('ended')->default(false);

            $table->timestamps();

            // Composite unique: one state record per (user, session, coach)
            $table->unique(['user_id', 'session_id', 'coach_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sisly_coach_states');
    }
};
