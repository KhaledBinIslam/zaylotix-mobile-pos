<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Inertia\Inertia;

/**
 * The public "/" page for a guest — a proper intro (what Zaylotix is, what
 * it does, who it's for) instead of dropping straight into the login form.
 * Previously root just redirect()->route('login'), which showed the login
 * form (with a prefilled demo login + hardcoded "Owner: Khaled Bin Islam"
 * footer) as the very first thing any visitor — customer, competitor,
 * search engine — saw. See routes/web.php's root route: authenticated
 * shop/admin users still redirect straight past this, unchanged.
 */
class LandingController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Landing/Index', [
            'whatsappContact' => SiteSetting::current()->whatsapp_contact,
        ]);
    }
}
