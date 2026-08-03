<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    public const TYPE_STANDARD = 'standard';
    public const TYPE_DRIVE_THROUGH = 'drive_through';

    protected $fillable = [
        'store_id',
        'name',
        'room_name',
        'type',
    ];

    public function isDriveThrough(): bool
    {
        return $this->type === self::TYPE_DRIVE_THROUGH;
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function media()
    {
        return $this->hasMany(StationMedia::class)
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }
}