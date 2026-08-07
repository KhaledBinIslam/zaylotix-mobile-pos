<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the `admin` middleware on the route
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:shops,phone'],
            'area' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'business_type_id' => ['required', 'exists:business_types,id'],
            'sales_mode' => ['required', 'in:scan,manual,both'],
            'plan' => ['required', 'in:trial,monthly,yearly'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            // null = fall back to StaffController::DEFAULT_STAFF_CAP (Business default)
            'staff_limit' => ['nullable', 'integer', 'min:0'],
            'subscription_start' => ['required', 'date'],
            'subscription_expiry' => ['required', 'date', 'after_or_equal:subscription_start'],
            'lang' => ['required', 'in:bn,en'],

            'owner_password' => ['required', 'string', 'min:4'],

            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'exists:features,key'],
        ];
    }
}
