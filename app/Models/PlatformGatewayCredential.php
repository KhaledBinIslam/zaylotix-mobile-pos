<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zaylotix's OWN bKash/Nagad/SSLCommerz merchant account -- for shop owners
 * paying their Zaylotix subscription. Completely separate from
 * PaymentGatewayCredential, which is each shop's own account for accepting
 * money from their customers. Single row per provider, not shop-scoped.
 */
class PlatformGatewayCredential extends Model
{
    protected $fillable = ['provider', 'credentials', 'is_active'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /** Mirrors PaymentGatewayCredential::maskedSummary() -- enough for the admin to confirm which account is connected, never enough to reconstruct the secret. */
    public function maskedSummary(): string
    {
        $idField = match ($this->provider) {
            'bkash' => $this->credentials['username'] ?? null,
            'nagad' => $this->credentials['merchant_id'] ?? null,
            'sslcommerz' => $this->credentials['store_id'] ?? null,
            default => null,
        };

        if (! $idField) {
            return 'configured';
        }

        $visible = substr($idField, 0, 3);

        return $visible.str_repeat('•', max(3, strlen($idField) - 3));
    }
}
