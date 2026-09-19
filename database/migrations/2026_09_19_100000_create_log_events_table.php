<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_events', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16);
            $table->string('service', 64);
            $table->text('message');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['level', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_events');
    }
};
