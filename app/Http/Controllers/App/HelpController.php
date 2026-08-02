<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Inertia\Inertia;

class HelpController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Help/Index', [
            'faqs' => Faq::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['question_bn', 'question_en', 'answer_bn', 'answer_en']),
        ]);
    }
}
