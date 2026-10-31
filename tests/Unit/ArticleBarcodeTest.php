<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Support\ArticleBarcode;
use Tests\TestCase;

/**
 * Pure string-logic tests against the real cost-code examples Khaled gave
 * (and the real barcodes found in docs/import/BabyPark_Product_Entry_1.xlsx
 * — see docs/ARTICLE_SERIES_PLAN.md). No DB writes — a plain in-memory
 * Shop model is enough since costCodeDigitMap()/colorCodeMap() just read
 * cast attributes.
 */
class ArticleBarcodeTest extends TestCase
{
    public function test_encode_cost_matches_khaleds_real_examples(): void
    {
        $shop = new Shop();

        $this->assertSame('NSXXZ', ArticleBarcode::encodeCost($shop, 1300));
        $this->assertSame('NSRXZ', ArticleBarcode::encodeCost($shop, 1350));
        $this->assertSame('NIRXZ', ArticleBarcode::encodeCost($shop, 1450));
        $this->assertSame('NJRXZ', ArticleBarcode::encodeCost($shop, 1650));
        $this->assertSame('NRXXZ', ArticleBarcode::encodeCost($shop, 1500));
    }

    public function test_encode_cost_returns_null_for_an_unmapped_digit(): void
    {
        $shop = new Shop();

        // 2, 7, 8, 9 are deliberately unset in the default map
        $this->assertNull(ArticleBarcode::encodeCost($shop, 1200));
        $this->assertNull(ArticleBarcode::encodeCost($shop, 1700));
    }

    public function test_decode_cost_strips_an_optional_trailing_z(): void
    {
        $shop = new Shop();

        // confirmed real-file fact: the same cost appears both with and
        // without the Z suffix
        $this->assertSame(1350, ArticleBarcode::decodeCost($shop, 'NSRXZ'));
        $this->assertSame(1350, ArticleBarcode::decodeCost($shop, 'NSRX'));
        $this->assertSame(1450, ArticleBarcode::decodeCost($shop, 'NIRXZ'));
        $this->assertSame(1450, ArticleBarcode::decodeCost($shop, 'NIRX'));
    }

    /**
     * Khaled confirmed the trailing Z is compulsory, not the optional
     * decoration the import file made it look like — every cost code on
     * his shelves carries one, so every code this encodes must too.
     */
    public function test_every_encoded_cost_ends_with_the_z_terminator(): void
    {
        $shop = new Shop();

        foreach ([0, 1, 5, 60, 1300, 100000] as $cost) {
            $code = ArticleBarcode::encodeCost($shop, $cost);
            $this->assertNotNull($code, "cost {$cost} should encode with the default map");
            $this->assertStringEndsWith('Z', $code);
            // the Z is a terminator, not a digit — it must survive the round trip
            $this->assertSame($cost, ArticleBarcode::decodeCost($shop, $code));
        }
    }

    public function test_an_unencodable_cost_gets_no_bare_z_instead_of_a_code(): void
    {
        $shop = new Shop();

        // 2 is unmapped by default — the answer is "can't encode", never "Z"
        $this->assertNull(ArticleBarcode::encodeCost($shop, 2));
    }

    public function test_decode_cost_returns_null_for_an_unrecognized_letter(): void
    {
        $shop = new Shop();

        $this->assertNull(ArticleBarcode::decodeCost($shop, 'QQQQ'));
    }

    public function test_a_shops_own_digit_map_override_is_used_over_the_default(): void
    {
        $shop = new Shop(['cost_code_digit_map' => ['2' => 'K', '7' => 'T', '8' => 'U', '9' => 'V']]);

        $this->assertSame('NKXXZ', ArticleBarcode::encodeCost($shop, 1200));
        $this->assertSame(1200, ArticleBarcode::decodeCost($shop, 'NKXX'));
    }

    public function test_build_article_barcode_matches_the_real_example(): void
    {
        $shop = new Shop();

        $barcode = ArticleBarcode::buildArticleBarcode($shop, 'SMS', 'L', 10001, 'NSXXZ');

        $this->assertSame('SMS-L-10001-NSXXZ', $barcode);
    }

    public function test_build_article_barcode_respects_a_custom_template(): void
    {
        $shop = new Shop(['article_barcode_template' => '{CAT}{SERIAL}-{MFR}']);

        $barcode = ArticleBarcode::buildArticleBarcode($shop, 'SMS', 'L', 10001, 'NSXXZ');

        $this->assertSame('L10001-SMS', $barcode);
    }

    public function test_build_variant_barcode_appends_color_code_and_size(): void
    {
        $shop = new Shop(['color_code_map' => ['সিলভার' => 'SIL']]);

        $barcode = ArticleBarcode::buildVariantBarcode($shop, 'SMS-L-10001-NSXXZ', 'সিলভার', '38');

        $this->assertSame('SMS-L-10001-NSXXZ-SIL-38', $barcode);
    }

    public function test_build_variant_barcode_falls_back_to_the_color_name_when_unmapped(): void
    {
        $shop = new Shop();

        $barcode = ArticleBarcode::buildVariantBarcode($shop, 'SMS-L-10001-NSXXZ', 'White', '38');

        $this->assertSame('SMS-L-10001-NSXXZ-WHITE-38', $barcode);
    }

    public function test_build_variant_barcode_omits_the_color_segment_when_theres_no_color(): void
    {
        $shop = new Shop();

        $barcode = ArticleBarcode::buildVariantBarcode($shop, 'SMS-L-10001-NSXXZ', null, '38');

        $this->assertSame('SMS-L-10001-NSXXZ-38', $barcode);
    }

    public function test_build_color_barcode_matches_khaleds_own_example(): void
    {
        $shop = new Shop(['color_code_map' => ['SILVER' => 'SIL']]);

        $barcode = ArticleBarcode::buildColorBarcode($shop, 'SMS-L-10001-NSXXZ', 'SILVER');

        $this->assertSame('SMS-L-10001-NSXXZ-SIL', $barcode);
    }

    public function test_build_color_barcode_omits_the_color_segment_when_theres_no_color(): void
    {
        $shop = new Shop();

        $barcode = ArticleBarcode::buildColorBarcode($shop, 'SMS-L-10001-NSXXZ', null);

        $this->assertSame('SMS-L-10001-NSXXZ', $barcode);
    }

    public function test_build_color_barcode_respects_a_custom_sticker_template(): void
    {
        $shop = new Shop(['sticker_barcode_template' => '{COLOR}/{ARTICLE}', 'color_code_map' => ['SILVER' => 'SIL']]);

        $barcode = ArticleBarcode::buildColorBarcode($shop, 'SMS-L-10001-NSXXZ', 'SILVER');

        $this->assertSame('SIL/SMS-L-10001-NSXXZ', $barcode);
    }

    public function test_suggest_color_code_uses_khaleds_confirmed_table(): void
    {
        $this->assertSame('SIL', ArticleBarcode::suggestColorCode('SILVER'));
        $this->assertSame('KHK', ArticleBarcode::suggestColorCode('KHAKI'));
        $this->assertSame('GLD', ArticleBarcode::suggestColorCode('GOLDEN'));
        $this->assertSame('WHT', ArticleBarcode::suggestColorCode('WHITE'));
        $this->assertSame('BLK', ArticleBarcode::suggestColorCode('BLACK'));
        $this->assertSame('BRN', ArticleBarcode::suggestColorCode('BROWN'));
        $this->assertSame('COF', ArticleBarcode::suggestColorCode('COFFEE'));
        $this->assertSame('MRN', ArticleBarcode::suggestColorCode('MAROON'));
        $this->assertSame('GRY', ArticleBarcode::suggestColorCode('GREY'));
    }

    public function test_suggest_color_code_falls_back_to_first_three_letters_for_an_unlisted_color(): void
    {
        $this->assertSame('PIN', ArticleBarcode::suggestColorCode('Pink'));
    }
}
