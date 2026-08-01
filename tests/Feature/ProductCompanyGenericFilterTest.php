<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ProductCompanyGenericFilterTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_save_a_products_company(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/products', [
            'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10, 'company' => 'Beximco',
        ])->assertRedirect();

        $this->assertSame('Beximco', Product::where('name', 'Napa')->first()->company);
    }

    public function test_stock_page_filters_by_company(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10, 'company' => 'Beximco']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Seclo', 'cost' => 1, 'price' => 2, 'stock' => 10, 'company' => 'Square']);

        $response = $this->actingAs($owner, 'web')->get('/app/stock?company=Beximco');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Napa')
        );
    }

    public function test_stock_page_filters_by_generic_name(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10, 'generic_name' => 'Paracetamol']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Ace', 'cost' => 1, 'price' => 2, 'stock' => 10, 'generic_name' => 'Paracetamol']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Seclo', 'cost' => 1, 'price' => 2, 'stock' => 10, 'generic_name' => 'Omeprazole']);

        $response = $this->actingAs($owner, 'web')->get('/app/stock?generic_name=Paracetamol');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('products.data', 2));
    }

    public function test_company_and_generic_lists_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopB->id, 'name' => 'Other', 'cost' => 1, 'price' => 2, 'stock' => 10, 'company' => 'ShopB Company']);

        $response = $this->actingAs($ownerA, 'web')->get('/app/stock');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('companies', []));
    }
}
