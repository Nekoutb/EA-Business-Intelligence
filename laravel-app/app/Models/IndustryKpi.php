<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndustryKpi extends Model
{
    protected $fillable = [
        'industry_id', 'kpi_key', 'label', 'description', 'formula_key',
        'module_key', 'sort_order', 'active', 'unit', 'color_class', 'icon',
    ];

    protected $casts = [
        'active'     => 'bool',
        'sort_order' => 'int',
    ];

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }
}
