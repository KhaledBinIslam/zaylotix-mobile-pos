<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Read-only: dumps the shop-level settings a clone-into-new-shop operation
 * needs to copy (business type, plan, features, article/barcode/size-group
 * config, memo/label settings) — built for cloning Baby Park's setup into
 * Liberty Bali without guessing field values via tinker (see house
 * convention: dedicated commands only, tinker output is unreliable here).
 */
class ShopsShowSettings extends Command
{
    protected $signature = 'shops:show-settings {--shop=} {--name=}';

    protected $description = 'Read-only: dumps a shop\'s settings relevant to cloning it into a new shop.';

    public function handle(): int
    {
        $shop = $this->option('shop')
            ? Shop::withoutGlobalScopes()->find($this->option('shop'))
            : Shop::withoutGlobalScopes()->where('name', 'like', '%'.$this->option('name').'%')->first();

        if (! $shop) {
            $this->error('শপ পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $this->line("id: {$shop->id}");
        $this->line("name: {$shop->name} / {$shop->name_en}");
        $this->line('business_type_id: '.($shop->business_type_id ?? 'NULL').' (slug: '.($shop->businessType?->slug ?? 'NULL').')');
        $this->line("parent_shop_id: ".($shop->parent_shop_id ?? 'NULL'));
        $this->line("sales_mode: {$shop->sales_mode}");
        $this->line("lang: {$shop->lang}");
        $this->line("plan: {$shop->plan}, account_type: {$shop->account_type}, monthly_fee: {$shop->monthly_fee}, staff_limit: {$shop->staff_limit}");
        $this->line("vat_mode: {$shop->vat_mode}, vat_rate: {$shop->vat_rate}");
        $this->line('hardware_scanner_enabled: '.($shop->hardware_scanner_enabled ? 'true' : 'false'));
        $this->line('features: '.$shop->features()->pluck('key')->implode(', '));
        $this->line('memo_settings: '.json_encode($shop->memo_settings));
        $this->line('label_settings: '.json_encode($shop->label_settings));
        $this->line('article_barcode_template: '.json_encode($shop->article_barcode_template));
        $this->line('cost_code_digit_map: '.json_encode($shop->cost_code_digit_map));
        $this->line('color_code_map: '.json_encode($shop->color_code_map));
        $this->line('kids_shoe_size_groups: '.json_encode($shop->kids_shoe_size_groups));
        $this->line('size_group_category_codes: '.json_encode($shop->size_group_category_codes));
        $this->line('sticker_barcode_template: '.json_encode($shop->sticker_barcode_template));
        $this->line('barcode_include_size: '.json_encode($shop->barcode_include_size));
        $this->line('default_variant_sizes: '.json_encode($shop->default_variant_sizes));
        $this->line('default_variant_colors: '.json_encode($shop->default_variant_colors));
        $this->line('categories: '.\App\Models\ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->line('categories with a size_set_id: '.\App\Models\ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNotNull('size_set_id')->count());
        $this->line('products: '.\App\Models\Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->count());
        $this->line('variants: '.\App\Models\ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->count());
        $this->line('price tier rows: '.\App\Models\ProductPriceTier::withoutGlobalScopes()->whereIn('product_id', \App\Models\Product::withoutGlobalScopes()->where('shop_id', $shop->id)->pluck('id'))->count());
        $users = \App\Models\User::withoutGlobalScopes()->where('shop_id', $shop->id)->get(['role', 'phone']);
        $this->line('users: '.$users->map(fn ($u) => "{$u->role}:{$u->phone}")->implode(' | '));

        return self::SUCCESS;
    }
}
