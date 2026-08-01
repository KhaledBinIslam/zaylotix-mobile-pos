<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Onboarding/Index', [
            'shop' => Tenancy::shop(),
        ]);
    }

    public function complete()
    {
        Tenancy::shop()->update(['onboarded_at' => now()]);

        return redirect()->route('app.home');
    }
}
