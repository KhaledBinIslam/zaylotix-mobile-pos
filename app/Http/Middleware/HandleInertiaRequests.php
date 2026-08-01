<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Models\WorkPeriod;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $shopUser = Auth::guard('web')->user();
        $admin = Auth::guard('admin')->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $shopUser,
                'admin' => $admin,
            ],
            'shop' => fn () => $shopUser ? Tenancy::shop() : null,
            // when an admin is impersonating this shop's owner, every
            // page carries this so AppLayout can show a persistent
            // "you're viewing as X — exit" banner that's never mistakable
            // for the owner's own normal session
            'impersonating' => fn () => $request->session()->has('impersonating_admin_id')
                ? ['adminName' => Admin::find($request->session()->get('impersonating_admin_id'))?->name]
                : null,
            'features' => fn () => $shopUser ? Tenancy::shop()?->featureKeys()->values() : [],
            // "A Zaylotix product" credit shown on every printed memo/label —
            // falls back to plain text (see the Vue templates) until an
            // admin actually uploads one, so this is never a broken <img>
            'platformLogoUrl' => fn () => SiteSetting::current()->logo_url,
            'notifications' => fn () => $shopUser ? [
                'unread_count' => $shopUser->unreadNotifications()->count(),
                'items' => $shopUser->notifications()->latest()->limit(15)->get()->map(fn ($n) => [
                    'id' => $n->id,
                    'title' => $n->data['title'] ?? '',
                    'message' => $n->data['message'] ?? '',
                    'read' => $n->read_at !== null,
                    'created_at' => $n->created_at->diffForHumans(),
                ])->values(),
            ] : null,
            // entirely optional shift/cash-drawer tracking — null on every
            // page for a shop that's never opened one, so AppLayout's banner
            // simply doesn't render and nothing else changes
            'activeWorkPeriod' => fn () => $shopUser ? WorkPeriod::whereNull('closed_at')->with('openedByUser:id,name')->first() : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
