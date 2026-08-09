<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Onboarding/Index', [
            // route isn't owner-restricted (a cashier could navigate here
            // directly even though HomeController only ever redirects an
            // owner into it) — never the raw model, see Shop::toArrayForUser()
            'shop' => Tenancy::shop()?->toArrayForUser(Auth::guard('web')->user()),
        ]);
    }

    public function complete()
    {
        Tenancy::shop()->update(['onboarded_at' => now()]);

        return redirect()->route('app.home');
    }
}
