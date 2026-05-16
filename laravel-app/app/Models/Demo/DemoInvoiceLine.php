<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoInvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'product_id', 'description',
        'qty', 'unit_price', 'subtotal', 'cogs_amount',
    ];

    protected $casts = [
        'qty'         => 'decimal:3',
        'unit_price'  => 'decimal:2',
        'subtotal'    => 'decimal:2',
        'cogs_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(DemoInvoice::class, 'invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(DemoProduct::class, 'product_id');
    }
}
