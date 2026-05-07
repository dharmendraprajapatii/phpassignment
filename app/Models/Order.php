<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'batch_id','order_id','placed_at','address',
        'country','value','currency','weight',
        'payment_mode','ingestion_source','source_reference',
        'city','dispatch_date','converted_value'
    ];

    protected $casts = [
        'is_deferred' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];
}
