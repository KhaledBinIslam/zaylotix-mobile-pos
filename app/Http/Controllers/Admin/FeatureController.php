<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Support\FeatureRecommendations;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeatureController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Features/Index', [
            'features' => Feature::orderBy('category')->orderBy('label_en')->get(),
            'recommendedFor' => FeatureRecommendations::byFeatureKey(),
        ]);
    }

    /** New capabilities can be added here without touching any other code — shops simply won't have them until granted. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'unique:features,key'],
            'label_bn' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        Feature::create($data);

        return back()->with('success', 'Feature added.');
    }

    public function update(Request $request, Feature $feature)
    {
        $data = $request->validate([
            'label_bn' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $feature->update($data);

        return back()->with('success', 'Feature updated.');
    }
}
