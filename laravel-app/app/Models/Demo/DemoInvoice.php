<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoInvoice extends Model
{
    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'invoice_date', 'due_date',
        'post_date', 'status', 'amount_ht', 'vat_amount', 'total',
        'has_po', 'po_number', 'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'post_date'    => 'date',
        'amount_ht'    => 'decimal:2',
        'vat_amount'   => 'decimal:2',
        'total'        => 'decimal:2',
        'has_po'       => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DemoCustomer::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DemoInvoiceLine::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DemoPayment::class, 'invoice_id');
    }

    /** Net of payments applied — drives AR + DSO calculations. */
    public function outstandingBalance(): float
    {
        return (float) $this->total - (float) $this->payments()->sum('amount');
    }
}
