<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Tenant setting — generic key/value store, replaces .env for tenant config.
 *
 * Secrets (keys matching /KEY|SECRET|PASSWORD|TOKEN/i) are transparently
 * encrypted on save and decrypted on read via attribute mutators.
 */
class TenantSetting extends Model
{
    protected $fillable = ['tenant_id', 'key', 'value', 'is_encrypted'];

    protected $casts = [
        'is_encrypted' => 'bool',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Determine whether a key should be stored encrypted. */
    public static function shouldEncrypt(string $key): bool
    {
        return (bool) preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/i', $key);
    }

    /**
     * Decrypt on read. We tolerate values written before encryption was
     * enabled (is_encrypted=false) so the migration path is forgiving.
     */
    public function getValueAttribute($raw)
    {
        if ($raw === null || $raw === '') return $raw;
        if (!$this->is_encrypted) return $raw;
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            return $raw; // fail open: corrupt value surfaces as-is rather than crashing
        }
    }

    /** Encrypt on write when the key is a secret-class field. */
    public function setValueAttribute($value): void
    {
        if ($value !== null && $value !== '' && self::shouldEncrypt($this->key ?? '')) {
            $this->attributes['value'] = Crypt::encryptString((string) $value);
            $this->attributes['is_encrypted'] = true;
        } else {
            $this->attributes['value'] = $value;
            $this->attributes['is_encrypted'] = false;
        }
    }
}
