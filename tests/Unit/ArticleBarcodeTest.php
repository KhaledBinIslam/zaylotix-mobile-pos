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

        $this->assertSame('NSXX', ArticleBarcode::encodeCost($shop, 1300));
        $this->assertSame('NSRX', ArticleBarcode::encodeCost($shop, 1350));
        $this->assertSame('NIRX', ArticleBarcode::encodeCost($shop, 1450));
        $this->assertSame('NJRX', ArticleBarcode::encodeCost($shop, 1650));
        $this->assertSame('NRXX', ArticleBarcode::encodeCost($shop, 1500));
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

    public function test_decode_cost_returns_null_for_an_unrecognized_letter(): void
    {
        $shop = new Shop();

        $this->assertNull(ArticleBarcode::decodeCost($shop, 'QQQQ'));
    }

    public function test_a_shops_own_digit_map_override_is_used_over_the_default(): void
    {
        $shop = new Shop(['cost_code_digit_map' => ['2' => 'K', '7' => 'T', '8' => 'U', '9' => 'V']]);

        $this->assertSame('NKXX', ArticleBarcode::encodeCost($shop, 1200));
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
}
