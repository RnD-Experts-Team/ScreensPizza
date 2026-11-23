<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_id is the pizzasys user id, now that users replicate here over NATS.
 * Checks are off while the key is added so existing rows are kept as they
 * are, even one whose user has not replicated yet. Restrict, not cascade:
 * a user's usage history outlives them (UserDeletedHandler keeps the row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('screen_usage_segments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::table('screen_usage_segments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
