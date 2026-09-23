<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PaymentGatewayCredential extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'provider', 'credentials', 'is_active'];

    protected function casts(): array
    {
        return [
            // encrypted at rest with the app's own APP_KEY — Eloquent
            // transparently decrypts on read/encrypts on write, so every
            // other line of code just sees a plain array; nothing except
            // this cast (and whoever holds APP_KEY) can ever read the raw
            // column value
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * A display-safe summary of the stored credentials — enough for the
     * owner to confirm which merchant account is connected, never enough
     * to reconstruct the actual secret. Only the identifying field per
     * provider is partially shown; every other key (app_secret,
     * store_passwd, etc.) never leaves the backend in any response.
     */
    public function maskedSummary(): string
    {
        // 'credentials' is decrypted with the app's CURRENT APP_KEY on every
        // read (see the cast above) — a row saved under a key that's since
        // rotated (e.g. .env regenerated after this row was written) throws
        // Illuminate\Contracts\Encryption\DecryptException here. That used
        // to take down this row's ENTIRE containing list (index() has no
        // try/catch of its own), turning "one stale credential" into a
        // blank/broken settings sheet with no visible reason — caught here
        // instead so it degrades to just this one row showing generic.
        try {
            $idField = match ($this->provider) {
                'bkash' => $this->credentials['username'] ?? null,
                'nagad' => $this->credentials['merchant_id'] ?? null,
                'sslcommerz' => $this->credentials['store_id'] ?? null,
                default => null,
            };
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return 'configured';
        }

        if (! $idField) {
            return 'configured';
        }

        $visible = substr($idField, 0, 3);

        return $visible.str_repeat('•', max(3, strlen($idField) - 3));
    }
}
