<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoSupplierBill extends Model
{
    protected $fillable = [
        'tenant_id', 'number', 'supplier_id', 'bill_date', 'due_date',
        'status', 'category', 'amount_ht', 'vat_amount', 'total',
        'has_po', 'po_number', 'notes',
    ];

    protected $casts = [
        'bill_date'  => 'date',
        'due_date'   => 'date',
        'amount_ht'  => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total'      => 'decimal:2',
        'has_po'     => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(DemoSupplier::class, 'supplier_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DemoSupplierPayment::class, 'bill_id');
    }

    public function outstandingBalance(): float
    {
        return (float) $this->total - (float) $this->payments()->sum('amount');
    }
}
