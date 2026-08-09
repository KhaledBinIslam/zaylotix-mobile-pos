<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsappCredential extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'credentials', 'is_active'];

    protected function casts(): array
    {
        return [
            // encrypted at rest with the app's own APP_KEY, same reasoning
            // as PaymentGatewayCredential — a raw DB dump never carries a
            // usable access token on its own
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /** Enough for the owner to confirm which number is connected, never enough to reconstruct the access token. */
    public function maskedSummary(): string
    {
        $phoneNumberId = $this->credentials['phone_number_id'] ?? null;
        if (! $phoneNumberId) {
            return 'configured';
        }

        $visible = substr($phoneNumberId, 0, 4);

        return $visible.str_repeat('•', max(3, strlen($phoneNumberId) - 4));
    }
}
