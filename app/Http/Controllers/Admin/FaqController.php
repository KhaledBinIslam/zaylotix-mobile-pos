<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FaqController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Faqs/Index', [
            'faqs' => Faq::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'question_bn' => ['required', 'string', 'max:255'],
            'question_en' => ['required', 'string', 'max:255'],
            'answer_bn' => ['required', 'string'],
            'answer_en' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        Faq::create($data + ['sort_order' => $data['sort_order'] ?? (Faq::max('sort_order') + 1)]);

        return back()->with('success', 'FAQ added.');
    }

    public function update(Request $request, Faq $faq)
    {
        $data = $request->validate([
            'question_bn' => ['required', 'string', 'max:255'],
            'question_en' => ['required', 'string', 'max:255'],
            'answer_bn' => ['required', 'string'],
            'answer_en' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ]);

        $faq->update($data);

        return back()->with('success', 'FAQ updated.');
    }

    public function destroy(Faq $faq)
    {
        $faq->delete();

        return back()->with('success', 'FAQ deleted.');
    }
}
