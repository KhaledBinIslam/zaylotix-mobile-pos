<?php

namespace App\Support;

use App\Models\Shop;

/**
 * Phase 40 (docs/FIX_PLAN.md, docs/CUSTOMIZATION_AUDIT.md) — the ONE place
 * that knows every shop-customizable presentation/behavior setting, what
 * its system default is, and which layer (শপ > ব্যবসা > ডিফল্ট) the
 * effective value came from.
 *
 * This deliberately does NOT introduce a new storage mechanism: every
 * value here already lives on the shops table with a defaults-merging
 * accessor on the Shop model (memoSettings(), labelSettings(),
 * labelHeader(), …). This class only makes that existing layer visible
 * and auditable — for the admin "কাস্টমাইজেশন" panel, the
 * `shop:effective-settings` command, and the tenant-leak/snapshot tests.
 * Adding a new customizable setting = add its row here, nothing else.
 */
class ShopCustomization
{
    public const SOURCE_SHOP = 'শপ';

    public const SOURCE_BUSINESS = 'ব্যবসা';

    public const SOURCE_DEFAULT = 'ডিফল্ট';

    /**
     * Every customization row for a shop: group → list of
     * ['key', 'label', 'value' (display string), 'source'].
     *
     * Resolution rules mirror the Shop accessors EXACTLY (they call them) —
     * this method never re-implements a default, so it can't drift from
     * what the app actually renders.
     */
    public static function effective(Shop $shop): array
    {
        $rows = [];

        // --- বারকোড লেবেল ---
        // label_header is business-level BY DESIGN (Shop::labelHeader()
        // resolves a branch through its main shop; a branch's own column is
        // never read) — so its source is only ever ব্যবসা or ডিফল্ট
        $businessHeader = $shop->parent_shop_id ? $shop->parentShop?->label_header : $shop->label_header;
        $rows['বারকোড লেবেল'][] = [
            'key' => 'label_header',
            'label' => 'লেবেল হেডার (স্টিকারের উপরের নাম)',
            'value' => $shop->labelHeader(),
            'source' => $businessHeader ? self::SOURCE_BUSINESS : self::SOURCE_DEFAULT,
            'resettable' => ! $shop->parent_shop_id && (bool) $shop->label_header,
        ];
        $rows['বারকোড লেবেল'][] = [
            'key' => 'sticker_barcode_template',
            'label' => 'স্টিকার বারকোড টেমপ্লেট',
            'value' => $shop->stickerBarcodeTemplate(),
            'source' => $shop->sticker_barcode_template ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
            'resettable' => (bool) $shop->sticker_barcode_template,
        ];
        $rows['বারকোড লেবেল'][] = [
            'key' => 'barcode_include_size',
            'label' => 'বারকোডে সাইজ অন্তর্ভুক্ত',
            'value' => $shop->barcodeIncludeSize() ? 'চালু' : 'বন্ধ',
            'source' => $shop->barcodeIncludeSize() ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
            'resettable' => $shop->barcodeIncludeSize(),
        ];
        $rows['বারকোড লেবেল'][] = [
            'key' => 'article_barcode_template',
            'label' => 'আর্টিকেল বারকোড টেমপ্লেট',
            'value' => $shop->articleBarcodeTemplate(),
            'source' => $shop->article_barcode_template ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
            'resettable' => (bool) $shop->article_barcode_template,
        ];

        // --- লেবেল সেটিংস (প্রতিটি কী আলাদা করে, কোনটা শপ নিজে বদলেছে দেখাতে) ---
        $rawLabel = $shop->label_settings ?? [];
        foreach ($shop->labelSettings() as $key => $value) {
            $rows['লেবেল সেটিংস'][] = [
                'key' => "label_settings.{$key}",
                'label' => $key,
                'value' => self::display($value),
                'source' => array_key_exists($key, $rawLabel) ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
                'resettable' => false, // পুরো ব্লক একসাথে reset হয় (label_settings)
            ];
        }

        // --- মেমো সেটিংস ---
        $rawMemo = $shop->memo_settings ?? [];
        foreach ($shop->memoSettings() as $key => $value) {
            $rows['মেমো সেটিংস'][] = [
                'key' => "memo_settings.{$key}",
                'label' => $key,
                'value' => self::display($value),
                'source' => array_key_exists($key, $rawMemo) ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
                'resettable' => false,
            ];
        }

        // --- অন্যান্য ---
        $rows['অন্যান্য'][] = [
            'key' => 'logo',
            'label' => 'লোগো',
            'value' => $shop->logo_path ? $shop->logo_path : '(নেই)',
            'source' => $shop->logo_path ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
            'resettable' => false, // মালিক নিজের settings পেজ থেকে বদলান
        ];
        $rows['অন্যান্য'][] = [
            'key' => 'mobile_nav_items',
            'label' => 'মোবাইল নেভ শর্টকাট',
            'value' => implode(', ', $shop->mobileNavItems()),
            'source' => $shop->mobile_nav_items ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
            'resettable' => (bool) $shop->mobile_nav_items,
        ];
        $rows['অন্যান্য'][] = [
            'key' => 'void_time_limit_days',
            'label' => 'ভয়েড সময়সীমা (দিন)',
            'value' => $shop->void_time_limit_days !== null ? (string) $shop->void_time_limit_days : 'সীমা নেই',
            'source' => $shop->void_time_limit_days !== null ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
            'resettable' => $shop->void_time_limit_days !== null,
        ];
        $rows['অন্যান্য'][] = [
            'key' => 'return_window_days',
            'label' => 'রিটার্ন সময়সীমা (দিন)',
            'value' => $shop->return_window_days !== null ? (string) $shop->return_window_days : 'সীমা নেই',
            'source' => $shop->return_window_days !== null ? self::SOURCE_SHOP : self::SOURCE_DEFAULT,
            'resettable' => $shop->return_window_days !== null,
        ];

        return $rows;
    }

    /**
     * Shop-level columns a given customization key may clear back to
     * default — the ONLY keys the admin reset endpoint accepts. A reset
     * never touches any other shop or any other column.
     */
    public const RESETTABLE_COLUMNS = [
        'label_header' => ['label_header'],
        'sticker_barcode_template' => ['sticker_barcode_template'],
        'article_barcode_template' => ['article_barcode_template'],
        'barcode_include_size' => ['barcode_include_size'],
        'label_settings' => ['label_settings'],
        'memo_settings' => ['memo_settings'],
        'mobile_nav_items' => ['mobile_nav_items'],
        'void_time_limit_days' => ['void_time_limit_days'],
        'return_window_days' => ['return_window_days'],
    ];

    private static function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'চালু' : 'বন্ধ';
        }
        if ($value === null || $value === '') {
            return '(খালি)';
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
