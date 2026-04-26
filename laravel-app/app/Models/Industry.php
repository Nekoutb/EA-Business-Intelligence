<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Industry extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'description', 'icon', 'sort_order', 'active',
    ];

    protected $casts = [
        'active'     => 'bool',
        'sort_order' => 'int',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(IndustryModule::class);
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(IndustryKpi::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
