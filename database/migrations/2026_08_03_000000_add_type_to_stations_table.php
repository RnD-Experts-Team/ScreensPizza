<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('type')->default('standard')->after('room_name');
        });

        DB::table('stores')->orderBy('id')->each(function ($store) {
            $hasDriveThrough = DB::table('stations')
                ->where('store_id', $store->id)
                ->where('type', 'drive_through')
                ->exists();

            if (!$hasDriveThrough) {
                DB::table('stations')->insert([
                    'store_id' => $store->id,
                    'name' => 'Drive Through',
                    'room_name' => 'drivethru-' . $store->store_number,
                    'type' => 'drive_through',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('stations')->where('type', 'drive_through')->delete();

        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
