<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One sticker run, so the shop can see what it has already labelled.
 *
 * Records "sent to the printer" — the honest limit of what the app can
 * know, since paper can still jam after that. The copies count is kept
 * rather than a bare flag so a second run shows as a second run.
 */
class LabelPrint extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'barcode', 'product_id', 'product_variant_id', 'product_color_barcode_id', 'user_id', 'copies',
    ];

    /**
     * "When was each barcode last printed, and how many have gone out" —
     * the whole page answered in one query rather than one per row.
     *
     * Business-wide, and keyed on the barcode: a sticker printed at Baby
     * Park is already on the shoe when that shoe is counted at Liberty,
     * and the two shops hold different ROWS for it but the same barcode.
     *
     * @return array<string,array{last: string, copies: int}>
     */
    public static function summaryFor(array $shopIds): array
    {
        return static::withoutGlobalScopes()
            ->whereIn('shop_id', $shopIds)
            ->whereNotNull('barcode')
            ->selectRaw('barcode, MAX(created_at) as last_at, SUM(copies) as total')
            ->groupBy('barcode')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->barcode => ['last' => (string) $row->last_at, 'copies' => (int) $row->total],
            ])
            ->all();
    }
}
