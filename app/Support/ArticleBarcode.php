<?php

namespace App\Support;

use App\Models\Shop;

/**
 * docs/ARTICLE_SERIES_PLAN.md §2-3. Two independent jobs: (1) the cost
 * digit<->letter code (e.g. 1300 <-> "NSXX"), and (2) assembling the full
 * article/variant barcode strings from a template. Kept separate from
 * ArticleSerial (the serial COUNTER) and from any DB write — pure string
 * manipulation only, easy to unit test without a shop in the database.
 */
class ArticleBarcode
{
    /**
     * The terminator every cost code ends with. Khaled's import data had
     * it on some rows and not others (the same cost 1350 appeared as both
     * "NSRXZ" and "NSRX"), so it was originally treated as an optional,
     * meaningless suffix and never added to a fresh encode. He has since
     * confirmed it is compulsory — every cost code carries it — so it is
     * now always appended on encode, which also makes newly entered
     * articles match the stickers already on his shelves.
     *
     * Decoding still strips it leniently rather than requiring it, so the
     * rows that were saved without one keep decoding correctly.
     *
     * This is why no digit may be mapped to "Z" (see
     * ArticleController::costCodeSettingsUpdate): a code ending in a
     * digit-Z would be indistinguishable from the terminator.
     */
    private const COST_SUFFIX = 'Z';

    /** Encodes a non-negative integer cost into its digit-letter code (always terminated by COST_SUFFIX), or null if any digit isn't mapped yet in this shop's settings (never a wrong guess). */
    public static function encodeCost(Shop $shop, int $cost): ?string
    {
        $map = $shop->costCodeDigitMap();
        $code = '';
        foreach (str_split((string) $cost) as $digit) {
            if (! isset($map[$digit])) {
                return null;
            }
            $code .= $map[$digit];
        }

        return $code.self::COST_SUFFIX;
    }

    /** Decodes a cost code back into an integer cost, or null if any letter doesn't map to a known digit. The trailing Z is stripped when present — encode always writes one, but rows saved before it was compulsory don't have it. */
    public static function decodeCost(Shop $shop, string $code): ?int
    {
        $code = rtrim($code);
        if (str_ends_with($code, self::COST_SUFFIX)) {
            $code = substr($code, 0, -1);
        }
        if ($code === '') {
            return null;
        }

        $reverse = array_flip($shop->costCodeDigitMap());
        $digits = '';
        foreach (str_split($code) as $letter) {
            if (! isset($reverse[$letter])) {
                return null;
            }
            $digits .= $reverse[$letter];
        }

        return (int) $digits;
    }

    /**
     * Assembles the article barcode from the shop's template (default
     * "{MFR}-{CAT}-{SERIAL}-{COSTCODE}") and its parts. `costCode` is
     * passed in already-built (see encodeCost()) so a barcode being
     * re-assembled from an EXISTING article never loses a Z suffix that
     * was manually preserved on import.
     */
    public static function buildArticleBarcode(Shop $shop, string $mfrCode, string $catCode, int $serial, string $costCode): string
    {
        return strtr($shop->articleBarcodeTemplate(), [
            '{MFR}' => $mfrCode,
            '{CAT}' => $catCode,
            '{SERIAL}' => (string) $serial,
            '{COSTCODE}' => $costCode,
        ]);
    }

    /**
     * Per-variant (sticker/scan) barcode: {article barcode}-{color code}-
     * {size}. Not just cosmetic — ProductVariant::stockPoolMemberIds()
     * already prefers an exact barcode match over size+color when pooling
     * stock across branches, so an identical, deterministic variant
     * barcode on both Baby Park and Liberty makes that existing matching
     * MORE reliable. A variant with no color (e.g. one-size-fits-all)
     * just omits that segment.
     */
    public static function buildVariantBarcode(Shop $shop, string $articleBarcode, ?string $color, string $size): string
    {
        $colorCode = self::colorCode($shop, $color);
        $segments = array_filter([$articleBarcode, $colorCode, $size], fn ($s) => $s !== null && $s !== '');

        return implode('-', $segments);
    }

    /** The shop's configured short code for a color, or the color name itself uppercased (ASCII only — a Bengali color name is left as-is, never mangled). */
    public static function colorCode(Shop $shop, ?string $color): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }

        $map = $shop->colorCodeMap();

        return $map[$color] ?? (ctype_alpha(str_replace(' ', '', $color)) ? strtoupper($color) : $color);
    }

    /**
     * docs/ARTICLE_SERIES_PLAN.md §9 — the "tier 2" color-level barcode
     * (one per product+color, shared by every size — $size is always ''
     * here, by construction; see ProductColorBarcode's own docblock for
     * why this is never per-size). {SIZE} resolving to empty gets cleaned
     * up, not left as a stray trailing/doubled hyphen. $articleBarcode is
     * passed in already-built, same reasoning as buildVariantBarcode()
     * above. Whether a LABEL actually prints this or the full
     * buildVariantBarcode() value is Shop::barcodeIncludeSize()'s call —
     * deliberately not this function's concern, so it stays a pure
     * function of its literal inputs.
     */
    public static function buildColorBarcode(Shop $shop, string $articleBarcode, ?string $color): string
    {
        $colorCode = self::colorCode($shop, $color) ?? '';

        $built = strtr($shop->stickerBarcodeTemplate(), [
            '{ARTICLE}' => $articleBarcode,
            '{COLOR}' => $colorCode,
            '{SIZE}' => '',
        ]);

        return self::cleanupHyphens($built);
    }

    /** Collapses a run of hyphens (left by an empty substituted token) and trims a leading/trailing one — never changes a template that had no empty token to begin with. */
    private static function cleanupHyphens(string $value): string
    {
        return trim(preg_replace('/-{2,}/', '-', $value), '-');
    }

    /**
     * First-3-letters suggestion for a brand-new color's code, confirmed
     * with Khaled against the real colors already in Baby Park's data —
     * never auto-applied, always a suggestion the owner can edit on the
     * color-code settings screen (see ArticleController::colorCodeSettingsEdit()).
     */
    public const COLOR_CODE_SUGGESTIONS = [
        'SILVER' => 'SIL', 'KHAKI' => 'KHK', 'GOLDEN' => 'GLD', 'WHITE' => 'WHT',
        'BLACK' => 'BLK', 'BROWN' => 'BRN', 'COFFEE' => 'COF', 'MAROON' => 'MRN', 'GREY' => 'GRY',
    ];

    /** A known color's suggested code, or (for an unlisted color) its own first 3 letters uppercased — always just a starting point, never forced. */
    public static function suggestColorCode(string $color): string
    {
        $key = strtoupper(trim($color));

        return self::COLOR_CODE_SUGGESTIONS[$key] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $color), 0, 3));
    }
}
