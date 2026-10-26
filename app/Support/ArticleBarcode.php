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
     * A trailing "Z" is a separate, optional, meaningless-to-cost suffix
     * — confirmed against Khaled's real import data
     * (docs/ARTICLE_SERIES_PLAN.md): the SAME cost (e.g. 1350) appears in
     * the file as both "NSRXZ" and "NSRX". Stripped before decoding,
     * carried through unchanged when re-encoding an existing barcode
     * (never invented on a fresh encode).
     */
    private const OPTIONAL_SUFFIX = 'Z';

    /** Encodes a non-negative integer cost into its digit-letter code, or null if any digit isn't mapped yet in this shop's settings (never a wrong guess). */
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

        return $code;
    }

    /** Decodes a cost code (with an optional trailing Z) back into an integer cost, or null if any letter doesn't map to a known digit. */
    public static function decodeCost(Shop $shop, string $code): ?int
    {
        $code = rtrim($code);
        if (str_ends_with($code, self::OPTIONAL_SUFFIX)) {
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
}
