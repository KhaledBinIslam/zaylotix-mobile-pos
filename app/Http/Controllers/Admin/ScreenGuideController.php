<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScreenGuide;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ScreenGuideController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/ScreenGuides/Index', [
            'guides' => ScreenGuide::orderBy('label')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'screen_key' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:screen_guides,screen_key'],
            'label' => ['required', 'string', 'max:255'],
            'text_bn' => ['required', 'string'],
            'text_en' => ['required', 'string'],
        ]);

        ScreenGuide::create($data);

        return back()->with('success', 'Guide added.');
    }

    public function update(Request $request, ScreenGuide $screenGuide)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'text_bn' => ['required', 'string'],
            'text_en' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]);

        $screenGuide->update($data);

        return back()->with('success', 'Guide updated.');
    }

    public function destroy(ScreenGuide $screenGuide)
    {
        $screenGuide->delete();

        return back()->with('success', 'Guide deleted.');
    }
}
