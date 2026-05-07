<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DispatchBatch extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'status', 'failed_orders', 'source'];

    protected $casts = [
        'failed_orders' => 'array',
    ];

    public function orders() {
        return $this->hasMany(Order::class, 'batch_id');
    }

    public function runs() {
        return $this->hasMany(DispatchRun::class, 'batch_id');
    }
}
