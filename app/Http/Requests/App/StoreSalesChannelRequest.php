<?php

namespace App\Http\Requests\App;

use App\Models\SalesChannel;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform_type' => ['required', Rule::in(SalesChannel::PLATFORM_TYPES)],
            'logo' => ['nullable', 'image', 'max:1024', 'mimes:jpg,jpeg,png,webp'],
            'remove_logo' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            // scoped to the shop, ignoring the channel being edited — same
            // pattern StoreProductRequest's barcode uniqueness rule uses
            'order_prefix' => [
                'required', 'string', 'max:20',
                Rule::unique('sales_channels', 'order_prefix')->where('shop_id', Tenancy::id())->ignore($this->route('channel')),
            ],
            'platform_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gateway_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Part 3 — see docs/FIX_PLAN.md's Phase 33 design note
            'default_courier_id' => ['nullable', Rule::exists('couriers', 'id')->where('shop_id', Tenancy::id())],
        ];
    }
}
