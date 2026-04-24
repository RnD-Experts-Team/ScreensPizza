<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LivekitIssuedToken extends Model
{
    protected $table = 'livekit_issued_tokens';

    protected $fillable = [
        'scope',
        'identity',
        'room',
        'token_hash',
        'issued_at',
        'expires_at',
        'revoked_at',
        'metadata',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];
}
