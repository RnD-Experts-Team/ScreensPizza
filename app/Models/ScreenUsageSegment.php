<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ScreenUsageSegment extends Model
{
    use HasUlids;

    public const KINDS = ['session', 'main', 'camera', 'mic', 'talk_all'];
    public const STATES = ['visible', 'no_face', 'covered', 'open', 'dead'];
    public const SURFACES = ['page', 'pip'];

    protected $fillable = [
        'id',
        'store_id',
        'station_id',
        'user_id',
        'user_name',
        'kind',
        'state',
        'surface',
        'started_at',
        'ended_at',
        'talking_seconds',
        'end_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'talking_seconds' => 'integer',
    ];
}
