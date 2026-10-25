<?php

namespace App\Http\Requests\App;

use App\Models\Courier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'charge_type' => ['required', Rule::in(Courier::CHARGE_TYPES)],
            'charge_config.amount' => ['required_if:charge_type,flat', 'nullable', 'numeric', 'min:0'],
            'charge_config.base' => ['required_if:charge_type,weight', 'nullable', 'numeric', 'min:0'],
            'charge_config.per_kg' => ['required_if:charge_type,weight', 'nullable', 'numeric', 'min:0'],
            'charge_config.inside_dhaka' => ['required_if:charge_type,zone', 'nullable', 'numeric', 'min:0'],
            'charge_config.outside_dhaka' => ['required_if:charge_type,zone', 'nullable', 'numeric', 'min:0'],
            'cod_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'return_charge' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
