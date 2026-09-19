<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('smart-triage.table', 'judgements'), function (Blueprint $table) {
            $table->id();
            $table->morphs('triageable');

            // Our question id, never sent to the model.
            $table->string('key');
            $table->string('type', 16);

            // The winning option for a choice; the nearest level label for a
            // score; null for a noul, whose answer is the number.
            $table->string('value')->nullable();
            $table->double('number')->nullable();

            // The distribution is the point. A choice that won on 0.34 against
            // a 0.31 runner-up is a different fact from one that won on 0.98,
            // and only this column knows which you have.
            $table->json('probabilities')->nullable();
            $table->double('confidence')->nullable();
            $table->json('legend')->nullable();

            // Two axes of invalidation: the record changed, or the question did.
            $table->char('state_fingerprint', 64);
            $table->char('questions_fingerprint', 64);
            $table->boolean('stale')->default(false);

            // The resolved version — jev-1.13.0, not the jev-latest we asked
            // for. Without it an old row is indistinguishable from a fresh one
            // after the alias moves.
            $table->string('model', 32);
            $table->unsignedInteger('input_tokens')->default(0);

            $table->timestamps();

            $table->unique(['triageable_type', 'triageable_id', 'key']);
            $table->index(['triageable_type', 'stale']);
            $table->index(['triageable_type', 'key', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('smart-triage.table', 'judgements'));
    }
};
