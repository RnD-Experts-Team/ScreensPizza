<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'id',
        'store_number'
    ];

    public function stations()
    {
        return $this->hasMany(Station::class);
    }
}
