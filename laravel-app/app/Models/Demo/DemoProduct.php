<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;

class DemoProduct extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'category', 'unit_price', 'default_cogs',
    ];

    protected $casts = [
        'unit_price'   => 'decimal:2',
        'default_cogs' => 'decimal:2',
    ];

    /** Gross-profit per unit at full price — sanity check for the demo views. */
    public function grossPerUnit(): float
    {
        return (float) $this->unit_price - (float) $this->default_cogs;
    }
}
