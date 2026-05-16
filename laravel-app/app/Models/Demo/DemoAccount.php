<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;

class DemoAccount extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'name', 'class', 'type', 'opening_balance',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'class'           => 'integer',
    ];
}
