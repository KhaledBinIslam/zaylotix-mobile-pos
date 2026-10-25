<?php

namespace App\Http\Requests\App;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnlineOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel_id' => ['required', Rule::exists('sales_channels', 'id')->where('shop_id', Tenancy::id())],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'items.*.product_variant_id' => ['nullable', Rule::exists('product_variants', 'id')->where('shop_id', Tenancy::id())],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.imei' => ['nullable', 'string', 'max:50'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_method' => [Rule::requiredIf(fn () => (float) $this->input('paid_amount', 0) > 0), 'nullable', Rule::in(['cash', 'bkash', 'nagad'])],
            'campaign_tag' => ['nullable', 'string', 'max:100'],
            'external_reference' => [
                'nullable', 'string', 'max:100',
                Rule::unique('online_orders', 'external_reference')->where('channel_id', $this->input('channel_id')),
            ],
        ];
    }
}
