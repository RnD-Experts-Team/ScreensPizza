<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('livekit_issued_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 50);
            $table->string('identity')->index();
            $table->string('room')->nullable()->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livekit_issued_tokens');
    }
};
