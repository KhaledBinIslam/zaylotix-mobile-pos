<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
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
    /**
     * The site root.
     *
     * Lives here rather than as a closure in routes/web.php because a
     * single closure route makes `route:cache` refuse the whole file —
     * and without that cache Laravel rebuilds the entire route table on
     * every request. Measured on production: 380ms of server time for a
     * plain login page, with bootstrap/cache holding no config or route
     * cache at all.
     */
    public function root()
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('app.home');
        }
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return $this->index();
    }

    public function index()
    {
        return Inertia::render('App/Landing/Index', [
            'whatsappContact' => SiteSetting::current()->whatsapp_contact,
            'playStoreUrl' => SiteSetting::current()->play_store_url,
            // The signed .apk only shows up once the TWA build (a separate,
            // local, Bubblewrap step) actually drops it here — no dead link
            // in the meantime.
            'apkAvailable' => File::exists(public_path('downloads/zaylotix-pos.apk')),
        ]);
    }
}
