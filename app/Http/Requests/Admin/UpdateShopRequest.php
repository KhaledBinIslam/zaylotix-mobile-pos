<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shopId = $this->route('shop')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('shops', 'phone')->ignore($shopId)],
            'area' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'business_type_id' => ['required', 'exists:business_types,id'],
            'sales_mode' => ['required', 'in:scan,manual,both'],
            'plan' => ['required', 'in:trial,monthly,yearly'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            // null = fall back to StaffController::DEFAULT_STAFF_CAP (Business default) —
            // set explicitly to raise/lower a specific shop's cashier cap regardless of package
            'staff_limit' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
            'subscription_start' => ['required', 'date'],
            'subscription_expiry' => ['required', 'date', 'after_or_equal:subscription_start'],
            'lang' => ['required', 'in:bn,en'],

            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'exists:features,key'],
        ];
    }
}
