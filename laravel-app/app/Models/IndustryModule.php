<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndustryModule extends Model
{
    protected $fillable = [
        'industry_id', 'module_key', 'enabled_by_default', 'required',
    ];

    protected $casts = [
        'enabled_by_default' => 'bool',
        'required'           => 'bool',
    ];

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }
}
