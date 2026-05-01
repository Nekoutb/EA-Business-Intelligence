<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PLCommentary extends Model
{
    protected $table = 'pl_commentaries';

    protected $fillable = [
        'tenant_id', 'year', 'month', 'view', 'commentary', 'saved_by',
    ];

    protected $casts = [
        'year'  => 'integer',
        'month' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function saver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }
}
