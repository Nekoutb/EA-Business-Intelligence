<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant cache of donation-recipient category resolution.
 *
 * Categories follow Article 7 CGI / LF Cameroon:
 *   1 = State / CTD / public-utility scientific/educational/welfare
 *       institutions, CNOSC, federations, named bodies (LF). Fully deductible.
 *   2 = Other recognised public-utility associations + approved research /
 *       educational / cultural bodies. Deductible up to 0.5% × CA_net ceiling.
 *   3 = All other recipients (political parties, individuals, unregistered
 *       associations, undocumented donations, foreign without DTA).
 *       Fully reintegrated; non-resident → +15% TSR.
 */
class PartnerDonationCategory extends Model
{
    public const CAT_FULLY_DEDUCTIBLE = 1;
    public const CAT_CAPPED           = 2;
    public const CAT_NON_DEDUCTIBLE   = 3;

    protected $fillable = [
        'tenant_id', 'odoo_partner_id', 'partner_name', 'partner_niu',
        'category', 'is_non_resident',
        'supporting_doc_type', 'supporting_doc_reference', 'supporting_doc_path',
        'notes', 'odoo_synced_at', 'odoo_sync_error',
    ];

    protected $casts = [
        'category'        => 'integer',
        'is_non_resident' => 'bool',
        'odoo_synced_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function categoryLabel(): string
    {
        return match ((int) $this->category) {
            self::CAT_FULLY_DEDUCTIBLE => 'Catégorie 1 — Fully deductible',
            self::CAT_CAPPED           => 'Catégorie 2 — Deductible up to ceiling',
            self::CAT_NON_DEDUCTIBLE   => 'Catégorie 3 — Non-deductible',
            default                    => 'Unresolved',
        };
    }
}
