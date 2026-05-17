<?php

namespace App\Models\Demo;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class DemoFixedAsset extends Model
{
    protected $fillable = [
        'tenant_id', 'asset_code', 'name', 'category', 'account_code',
        'acquisition_date', 'acquisition_cost', 'useful_life_months',
        'depreciation_method', 'disposal_date', 'disposal_value',
    ];

    protected $casts = [
        'acquisition_date'   => 'date',
        'disposal_date'      => 'date',
        'acquisition_cost'   => 'decimal:2',
        'disposal_value'     => 'decimal:2',
        'useful_life_months' => 'integer',
    ];

    /** Monthly straight-line depreciation. */
    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months <= 0) return 0;
        return (float) $this->acquisition_cost / (float) $this->useful_life_months;
    }

    /** Number of months elapsed between acquisition and the as-of date (capped at useful life). */
    public function monthsDepreciated(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? now()->startOfDay();
        $end = $this->disposal_date ?? $asOf;
        if ($asOf->lt($this->acquisition_date)) return 0;
        $months = (int) $this->acquisition_date->diffInMonths($end > $asOf ? $asOf : $end);
        return max(0, min($months, $this->useful_life_months));
    }

    public function accumulatedDepreciation(?Carbon $asOf = null): float
    {
        return $this->monthlyDepreciation() * $this->monthsDepreciated($asOf);
    }

    public function netBookValue(?Carbon $asOf = null): float
    {
        return max(0, (float) $this->acquisition_cost - $this->accumulatedDepreciation($asOf));
    }
}
