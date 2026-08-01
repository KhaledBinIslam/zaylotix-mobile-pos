<?php

namespace Tests\Feature;

use App\Models\MedicineCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Search-only lookup helper for the new-product form — never a source of price/stock (see MedicineCatalog's migration comment). */
class MedicineCatalogTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_search_finds_a_medicine_by_brand_name(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        MedicineCatalog::create(['name' => 'Napa', 'generic_name' => 'Paracetamol', 'company' => 'Beximco Pharmaceuticals']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/medicine-catalog/search?q=Napa');

        $response->assertOk()->assertJsonFragment(['name' => 'Napa']);
    }

    public function test_search_finds_a_medicine_by_generic_name(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        MedicineCatalog::create(['name' => 'Napa', 'generic_name' => 'Paracetamol', 'company' => 'Beximco Pharmaceuticals']);
        MedicineCatalog::create(['name' => 'Ace', 'generic_name' => 'Paracetamol', 'company' => 'Square Pharmaceuticals']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/medicine-catalog/search?q=Paracetamol');

        $response->assertOk();
        $this->assertCount(2, $response->json('results'));
    }

    public function test_search_requires_at_least_two_characters(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        MedicineCatalog::create(['name' => 'Napa', 'generic_name' => 'Paracetamol', 'company' => 'Beximco']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/medicine-catalog/search?q=N');

        $response->assertOk()->assertJson(['results' => []]);
    }

    public function test_the_seeded_starter_catalog_has_real_entries(): void
    {
        $this->seed(\Database\Seeders\MedicineCatalogSeeder::class);

        $this->assertGreaterThan(30, MedicineCatalog::count());
        $this->assertTrue(MedicineCatalog::where('name', 'Napa')->exists());
    }
}
