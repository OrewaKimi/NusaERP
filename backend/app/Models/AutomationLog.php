<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationLog extends Model
{
    protected $fillable = [
        'event',
        'target',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
