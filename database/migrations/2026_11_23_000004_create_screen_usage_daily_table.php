<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen usage per work day, rebuilt by usage:aggregate-daily from the segments: one row per
 * work day × store × station in the main tile (null = none) × manager, durations in seconds.
 * A work day holds the shifts that started on it, overnight included (ScreenUsageReport::workDayRows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->date('work_date');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('on_screen')->default(0);
            $table->unsignedInteger('present')->default(0);
            $table->unsignedInteger('away')->default(0);
            $table->unsignedInteger('no_face')->default(0);
            $table->unsignedInteger('covered')->default(0);
            $table->unsignedInteger('camera_off')->default(0);
            $table->unsignedInteger('mic_open')->default(0);
            $table->unsignedInteger('talking')->default(0);
            $table->unsignedInteger('silent')->default(0);
            $table->unsignedInteger('dead_mic')->default(0);
            $table->unsignedInteger('talk_all')->default(0);
            $table->unsignedInteger('pip')->default(0);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('kicks')->default(0);
            $table->timestamps();

            $table->index(['work_date', 'store_id']);
            $table->index(['user_id', 'work_date']);
        });

        // The work days stored above, so a stored day nobody worked is told from one not stored yet.
        Schema::create('screen_usage_aggregated_days', function (Blueprint $table) {
            $table->date('work_date')->primary();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_usage_aggregated_days');
        Schema::dropIfExists('screen_usage_daily');
    }
};
