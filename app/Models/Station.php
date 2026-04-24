<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'room_name',
    ];
}