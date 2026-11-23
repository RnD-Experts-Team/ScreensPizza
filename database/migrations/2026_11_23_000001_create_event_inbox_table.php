<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();       // CloudEvent ULID — idempotency key
            $table->string('subject', 255)->index();
            $table->string('source', 100)->nullable()->index();
            $table->string('stream', 100)->nullable()->index();
            $table->string('consumer', 100)->nullable()->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('parked_at')->nullable()->index(); // parked after max failures
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_inbox');
    }
};
