<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PLCommentary extends Model
{
    use \App\Models\Concerns\OnTenantDatabase;

    protected $table = 'pl_commentaries';

    protected $fillable = [
        'tenant_id', 'year', 'month', 'view', 'line_key', 'commentary', 'saved_by',
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
