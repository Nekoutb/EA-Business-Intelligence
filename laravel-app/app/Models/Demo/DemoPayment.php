<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoPayment extends Model
{
    protected $fillable = [
        'tenant_id', 'invoice_id', 'payment_date',
        'amount', 'method', 'reference',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(DemoInvoice::class, 'invoice_id');
    }
}
