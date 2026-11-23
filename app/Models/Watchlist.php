<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Watchlist extends Model
{
    protected $fillable = [
        'telegram_id', 'symbol', 'alert_mode',
        'target_bow', 'target_bob', 'target_sl', 'min_vol_ratio',
    ];

    protected $casts = [
        'target_bow'    => 'float',
        'target_bob'    => 'float',
        'target_sl'     => 'float',
        'min_vol_ratio' => 'float',
    ];
}