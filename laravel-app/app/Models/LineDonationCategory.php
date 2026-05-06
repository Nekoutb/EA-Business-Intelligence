<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-line override of donation-recipient category resolution.
 *
 * Companion to PartnerDonationCategory — partner table holds the default
 * category for each beneficiary, this model holds line-level overrides
 * the auditor sets when a specific posting needs to land in a different
 * bucket than the partner default would suggest.
 *
 * Resolution precedence at calc time:
 *   line override (this model)  →  partner default  →  Cat 3 fallback
 *
 * Categories follow Article 7 CGI / LF Cameroon (same enum as
 * PartnerDonationCategory):
 *   1 = Fully deductible (no ceiling) — State/CTD/named bodies
 *   2 = Deductible up to 0.5 % × CA_net ceiling — utilité publique
 *   3 = Non-deductible — political parties, individuals, foreign w/o DTA
 *
 * Cat 3 is the conservative fallback: when no override and no partner
 * default, lines resolve to Cat 3 ("if I haven't told you otherwise,
 * non-deductible until I prove otherwise").
 */
class LineDonationCategory extends Model
{
    public const CAT_FULLY_DEDUCTIBLE = 1;
    public const CAT_CAPPED           = 2;
    public const CAT_NON_DEDUCTIBLE   = 3;

    protected $fillable = [
        'tenant_id',
        'odoo_move_line_id',
        'odoo_move_id',
        'odoo_partner_id',
        'account_code',
        'line_date',
        'amount',
        'category',
        'note',
        'assigned_by',
    ];

    protected $casts = [
        'odoo_move_line_id' => 'integer',
        'odoo_move_id'      => 'integer',
        'odoo_partner_id'   => 'integer',
        'category'          => 'integer',
        'amount'            => 'decimal:2',
        'line_date'         => 'date',
        'assigned_by'       => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function categoryLabel(): string
    {
        return match ((int) $this->category) {
            self::CAT_FULLY_DEDUCTIBLE => 'Catégorie 1 — Fully deductible',
            self::CAT_CAPPED           => 'Catégorie 2 — Deductible up to ceiling',
            self::CAT_NON_DEDUCTIBLE   => 'Catégorie 3 — Non-deductible',
            default                    => 'Unknown',
        };
    }
}
