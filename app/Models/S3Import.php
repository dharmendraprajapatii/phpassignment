<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class S3Import extends Model
{
    protected $fillable = [
        'path',
        'status',
        'orders_count',
        'error_message',
        'ingested_at',
        'processed_at',
    ];

    protected $casts = [
        'ingested_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
