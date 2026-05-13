<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StationMedia extends Model
{
    protected $fillable = [
        'station_id',
        'type',
        'is_primary',
        'storage_disk',
        'path',
        'file_name',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'width',
        'height',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    protected $appends = [
        'url',
    ];

    protected $hidden = [
        'storage_disk',
        'path',
    ];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->storage_disk)->url($this->path);
    }
}
