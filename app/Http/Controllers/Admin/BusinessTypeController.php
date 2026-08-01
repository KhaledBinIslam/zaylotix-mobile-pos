<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BusinessTypeController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/BusinessTypes/Index', [
            'businessTypes' => BusinessType::orderBy('label_en')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'unique:business_types,slug'],
            'label_bn' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['in:expiry,batch,imei,size,color'],
        ]);

        BusinessType::create($data);

        return back()->with('success', 'Business type added.');
    }

    public function update(Request $request, BusinessType $businessType)
    {
        $data = $request->validate([
            'label_bn' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['in:expiry,batch,imei,size,color'],
            'is_active' => ['boolean'],
        ]);

        $businessType->update($data);

        return back()->with('success', 'Business type updated.');
    }
}
