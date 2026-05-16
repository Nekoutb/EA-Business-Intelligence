<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoJournalLine extends Model
{
    protected $fillable = [
        'entry_id', 'account_code', 'account_name',
        'debit', 'credit', 'partner', 'description',
    ];

    protected $casts = [
        'debit'  => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DemoJournalEntry::class, 'entry_id');
    }
}
