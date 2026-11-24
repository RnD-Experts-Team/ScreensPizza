<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each manager's monitoring numbers per work day (ScreenUsageReport::collectWorkDay()), so the
 * report reads stored days instead of their segments. Their time is merged across all their
 * stores, which per-store rows cannot give back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_usage_daily_users', function (Blueprint $table) {
            $table->id();
            $table->date('work_date');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->json('stats');
            $table->timestamps();

            $table->unique(['work_date', 'user_id']);
        });

        // The days stored so far have no rows above: forget them so they are worked out from
        // the segments until usage:aggregate-daily stores them again. Only derived rows go.
        DB::table('screen_usage_aggregated_days')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_usage_daily_users');
    }
};
