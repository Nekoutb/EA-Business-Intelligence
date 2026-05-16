<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoJournalEntry extends Model
{
    protected $fillable = [
        'tenant_id', 'entry_date', 'journal_code',
        'reference', 'description', 'posted',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted'     => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(DemoJournalLine::class, 'entry_id');
    }

    public function totalDebit(): float
    {
        return (float) $this->lines()->sum('debit');
    }
    public function totalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.01;
    }
}
