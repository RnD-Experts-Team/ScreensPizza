<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'store_number',
        'station_password',
    ];

    protected $hidden = [
        'station_password',
    ];

    protected $casts = [
        'station_password' => 'hashed',
    ];

    public function stations()
    {
        return $this->hasMany(Station::class);
    }

    protected static function booted(): void
    {
        static::created(function (Store $store) {
            Station::firstOrCreate(
                ['store_id' => $store->id, 'type' => Station::TYPE_DRIVE_THROUGH],
                ['name' => 'Drive Through', 'room_name' => 'drivethru-' . $store->store_number]
            );
        });
    }
}
