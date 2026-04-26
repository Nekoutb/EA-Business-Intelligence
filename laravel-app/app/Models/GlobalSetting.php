<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Global setting — platform-wide key/value store, managed by super admins only.
 *
 * Mirrors TenantSetting for shape and encryption rules, but holds values
 * that apply across every tenant (e.g. the AI provider/model/api key).
 *
 * Secrets (keys matching /KEY|SECRET|PASSWORD|TOKEN/i) are transparently
 * encrypted on save and decrypted on read via attribute mutators.
 */
class GlobalSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected $casts = [
        'is_encrypted' => 'bool',
    ];

    /** Determine whether a key should be stored encrypted. */
    public static function shouldEncrypt(string $key): bool
    {
        return (bool) preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/i', $key);
    }

    /** Look up a global setting value (decrypted automatically). */
    public static function lookup(string $key, $default = null)
    {
        $row = static::where('key', $key)->first();
        return $row?->value ?? $default;
    }

    /** Decrypt on read; tolerate plaintext rows for forward-migration safety. */
    public function getValueAttribute($raw)
    {
        if ($raw === null || $raw === '') return $raw;
        if (!$this->is_encrypted) return $raw;
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            return $raw;
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
