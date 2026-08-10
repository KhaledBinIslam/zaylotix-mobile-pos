<?php

namespace App\Http\Requests\App;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'emoji' => ['nullable', 'string', 'max:8'],
            'photo' => ['nullable', 'image', 'max:1024', 'mimes:jpg,jpeg,png,webp'],
            'remove_photo' => ['nullable', 'boolean'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'requires_prescription' => ['nullable', 'boolean'],
            // scoped to the current shop — an unscoped `exists:product_categories,id`
            // would accept a valid id belonging to a DIFFERENT shop (the exists
            // rule queries the raw table, bypassing the tenant scope), silently
            // saving a cross-shop reference that then just renders blank
            'category_id' => ['nullable', Rule::exists('product_categories', 'id')->where('shop_id', Tenancy::id())],
            'new_category_name' => ['nullable', 'string', 'max:255'],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('shop_id', Tenancy::id())],
            'new_unit_name' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'sold_by_weight' => ['nullable', 'boolean'],
            'weight_unit' => ['required_if:sold_by_weight,1', 'nullable', 'in:kg,litre'],
            'cost' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            // 'untracked'/'toggle' only make sense for a restaurant's own
            // cooked-food products (see Stock/Index.vue's isRestaurant
            // gate) — accepted from anyone since it's harmless either way,
            // an out-of-vertical shop's form simply never sends it
            'stock_mode' => ['nullable', 'in:tracked,untracked,toggle'],
            // a weighed product's stock is a real decimal (e.g. 12.5 kg on
            // the shelf); a regular piece-counted product still requires a
            // whole number — see the `numeric`-vs-`integer` split.
            // Required only in 'tracked' mode (the default, unaffected for
            // every existing product/vertical) — 'untracked'/'toggle' don't
            // take a real count from the form at all (see
            // ProductController::resolveStock()).
            'stock' => [
                Rule::requiredIf(fn () => $this->input('stock_mode', 'tracked') === 'tracked'),
                'nullable', 'numeric', 'min:0', ...($this->boolean('sold_by_weight') ? [] : ['integer']),
            ],
            // 'toggle' mode's own simple switch — see resolveStock()
            'available' => ['nullable', 'boolean'],
            'expiry_date' => ['nullable', 'date'],
            'batch_no' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'imei' => ['nullable', 'string', 'max:50'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
