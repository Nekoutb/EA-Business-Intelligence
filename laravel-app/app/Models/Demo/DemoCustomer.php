<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoCustomer extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'category', 'payment_terms_days',
        'country', 'contact_email',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(DemoInvoice::class, 'customer_id');
    }
}
