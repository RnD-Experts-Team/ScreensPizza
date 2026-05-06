<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
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
}
