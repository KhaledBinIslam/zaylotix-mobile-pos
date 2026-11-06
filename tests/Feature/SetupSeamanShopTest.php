<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEAMAN SHOE STORE — the second paid onboarding, provisioned by its own
 * idempotent command exactly the way SetupBabyPark provisioned the first.
 */
class SetupSeamanShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_standalone_paid_shop_with_owner_and_features(): void
    {
        $this->artisan('shop:setup-seaman', ['--password' => 'secret-for-test-1'])
            ->assertSuccessful();

        $shop = Shop::withoutGlobalScopes()->where('phone', '01833722077')->first();
        $this->assertNotNull($shop);
        $this->assertSame('SEAMAN SHOE STORE', $shop->name);
        $this->assertNull($shop->parent_shop_id); // standalone — no branch
        $this->assertSame('paid', $shop->account_type);
        $this->assertSame('monthly', $shop->plan);
        $this->assertSame('active', $shop->status);
        $this->assertSame('seamanstore.com', $shop->website);
        $this->assertSame('footwear', $shop->businessType?->slug);

        $owner = User::where('shop_id', $shop->id)->where('role', 'owner')->first();
        $this->assertNotNull($owner);
        $this->assertSame('01833722077', $owner->phone);

        foreach (['product_variants', 'suppliers', 'purchases', 'accounts', 'reports', 'barcode_printing'] as $key) {
            $this->assertTrue($shop->hasFeature($key), "missing feature: {$key}");
        }
        // deliberately NOT granted — no branch, no pool, not Baby Park's barcode scheme
        foreach (['shared_catalog', 'shared_stock_pool', 'online_business'] as $key) {
            $this->assertFalse($shop->hasFeature($key), "unexpected feature: {$key}");
        }

        // no demo data of any kind seeded into a paying shop
        $this->assertSame(0, \App\Models\Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_rerun_is_idempotent(): void
    {
        $this->artisan('shop:setup-seaman', ['--password' => 'secret-for-test-1'])->assertSuccessful();
        $this->artisan('shop:setup-seaman', ['--password' => 'secret-for-test-2'])->assertSuccessful();

        $this->assertSame(1, Shop::withoutGlobalScopes()->where('phone', '01833722077')->count());
        $shop = Shop::withoutGlobalScopes()->where('phone', '01833722077')->first();
        // the first owner user survives; a re-run never creates a second
        $this->assertSame(1, User::where('shop_id', $shop->id)->where('role', 'owner')->count());
        // an EXPLICIT password on a re-run is a deliberate reset and applies
        $owner = User::where('shop_id', $shop->id)->where('role', 'owner')->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-for-test-2', $owner->password));
    }

    /**
     * Live bug: a no-password RE-RUN printed a freshly generated password
     * that had been applied to nothing — anyone saving it would be saving
     * a password that opens nothing. A re-run without --password must say
     * the password is unchanged, never show one, and never change one.
     */
    public function test_a_rerun_without_password_neither_changes_nor_prints_one(): void
    {
        $this->artisan('shop:setup-seaman', ['--password' => 'secret-for-test-1'])->assertSuccessful();

        $this->artisan('shop:setup-seaman')
            ->expectsOutputToContain('পাসওয়ার্ড বদলায়নি')
            ->doesntExpectOutputToContain('এখনই সেভ করুন')
            ->assertSuccessful();

        $shop = Shop::withoutGlobalScopes()->where('phone', '01833722077')->first();
        $owner = User::where('shop_id', $shop->id)->where('role', 'owner')->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-for-test-1', $owner->password));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('shop:setup-seaman', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Shop::withoutGlobalScopes()->where('phone', '01833722077')->count());
    }

    public function test_rollback_refuses_once_a_sale_exists(): void
    {
        $this->artisan('shop:setup-seaman', ['--password' => 'secret-for-test-1'])->assertSuccessful();
        $shop = Shop::withoutGlobalScopes()->where('phone', '01833722077')->first();

        \App\Models\Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(),
            'time' => now()->format('H:i'), 'subtotal' => 100, 'discount' => 0, 'vat' => 0,
            'total' => 100, 'profit' => 10, 'payment_mode' => 'cash', 'counted_in_reports' => true,
        ]);

        $this->artisan('shop:setup-seaman', ['--rollback' => true])->assertFailed();
        $this->assertNotNull(Shop::withoutGlobalScopes()->where('phone', '01833722077')->first());
    }
}
