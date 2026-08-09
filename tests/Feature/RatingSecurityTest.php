<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Security-audit fix: the public /rate/{sale} page (reached by scanning a
 * QR code on a paper receipt, no login) used to accept a plain sequential
 * id — anyone could enumerate every sale on the platform, or post a rating
 * for a sale they never made. It's now behind Laravel's `signed` middleware,
 * only reachable via the exact link Sale::ratingUrl() generates.
 */
class RatingSecurityTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private static int $counter = 0;

    private function makeSale(int $shopId): Sale
    {
        return Sale::create([
            'shop_id' => $shopId, 'invoice_no' => 'INV-'.(++self::$counter), 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 100, 'discount' => 0, 'vat' => 0, 'total' => 100, 'profit' => 20, 'payment_mode' => 'cash',
        ]);
    }

    public function test_a_guessed_unsigned_url_is_rejected(): void
    {
        [$shop] = $this->createShopWithOwner();
        $sale = $this->makeSale($shop->id);

        // exactly what an attacker enumerating ids would try — no signature
        $this->get("/rate/{$sale->id}")->assertForbidden();
        $this->post("/rate/{$sale->id}", ['stars' => 1])->assertForbidden();
    }

    public function test_the_real_signed_link_works(): void
    {
        [$shop] = $this->createShopWithOwner();
        $sale = $this->makeSale($shop->id);

        $this->assertSame($sale->rating_url, URL::signedRoute('rate.show', ['sale' => $sale->id]));

        $this->get($sale->rating_url)->assertOk();
    }

    public function test_submitting_a_rating_requires_the_same_signed_url_the_page_was_opened_with(): void
    {
        [$shop] = $this->createShopWithOwner();
        $sale = $this->makeSale($shop->id);

        // mirrors Rate.vue's submit(): posts to the same path+query the
        // page was loaded with, not a hand-built plain route
        $this->post($sale->rating_url, ['stars' => 5, 'comment' => 'Great!'])->assertRedirect();

        // no authenticated tenant in this anonymous request (same as a real
        // customer's browser) — Tenancy::id() resolves to null, so the
        // assertion needs the same explicit bypass RatingController itself
        // uses, exactly like the app code does for the same reason
        $this->assertSame(1, SaleRating::withoutGlobalScopes()->where('sale_id', $sale->id)->count());
        $this->assertSame(5, SaleRating::withoutGlobalScopes()->where('sale_id', $sale->id)->first()->stars);
    }

    public function test_a_signature_minted_for_one_sale_does_not_work_on_another(): void
    {
        [$shop] = $this->createShopWithOwner();
        $saleA = $this->makeSale($shop->id);
        $saleB = $this->makeSale($shop->id);

        // swap the id in a legitimately-signed URL for a different sale —
        // the signature no longer matches this URL's query string
        $tampered = str_replace((string) $saleA->id, (string) $saleB->id, $saleA->rating_url);

        $this->get($tampered)->assertForbidden();
    }

    public function test_rate_limited_after_repeated_requests(): void
    {
        [$shop] = $this->createShopWithOwner();
        $sale = $this->makeSale($shop->id);

        for ($i = 0; $i < 20; $i++) {
            $this->get($sale->rating_url);
        }

        $this->get($sale->rating_url)->assertStatus(429);
    }
}
