<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoSupplier extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'category', 'payment_terms_days',
        'country', 'contact_email',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
    ];

    public function bills(): HasMany
    {
        return $this->hasMany(DemoSupplierBill::class, 'supplier_id');
    }
}
