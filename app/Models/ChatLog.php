<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'session_id',
        'role',
        'message',
        'ip_address',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
