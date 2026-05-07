<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchRun extends Model
{
    protected $fillable = [
        'batch_id','city','country','dispatch_date','total_value','currency','weather_summary'
    ];
}
