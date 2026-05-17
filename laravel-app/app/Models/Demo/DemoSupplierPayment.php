<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoSupplierPayment extends Model
{
    protected $fillable = [
        'tenant_id', 'bill_id', 'payment_date',
        'amount', 'method', 'reference',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(DemoSupplierBill::class, 'bill_id');
    }
}
