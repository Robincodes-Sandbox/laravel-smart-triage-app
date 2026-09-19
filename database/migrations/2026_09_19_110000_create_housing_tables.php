<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description');
            $table->unsignedSmallInteger('operatives')->default(0);
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('uprn', 16)->unique();
            $table->string('address');
            $table->string('postcode', 10);
            $table->string('block', 64)->nullable();
            $table->string('archetype', 32);
            $table->unsignedSmallInteger('built');

            // The facts that make an identical report mean different things.
            $table->boolean('damp_history')->default(false);
            $table->boolean('household_vulnerable')->default(false);
            $table->unsignedTinyInteger('floor')->default(0);

            $table->index(['block', 'damp_history']);
        });

        Schema::create('repair_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('room', 32);
            $table->text('summary');
            $table->timestamp('reported_at');
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->index(['reported_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_reports');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('trades');
    }
};
