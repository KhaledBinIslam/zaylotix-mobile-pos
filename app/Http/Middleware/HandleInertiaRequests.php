<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\ScreenGuide;
use App\Models\Shop;
use App\Models\SiteSetting;
use App\Models\User;
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
            // owner-only -- the branch switcher in AppLayout's header. Null
            // for a shop with no branches (every shop today, unchanged) or
            // for a staff account (always fixed to one branch, never sees
            // this at all). See BranchController + Tenancy::id().
            'branches' => fn () => $this->branchList($shopUser),
            // when an admin is impersonating this shop's owner, every
            // page carries this so AppLayout can show a persistent
            // "you're viewing as X — exit" banner that's never mistakable
            // for the owner's own normal session
            'impersonating' => fn () => $request->session()->has('impersonating_admin_id')
                ? ['adminName' => Admin::find($request->session()->get('impersonating_admin_id'))?->name]
                : null,
            'features' => fn () => $shopUser ? Tenancy::shop()?->featureKeys()->values() : [],
            // admin-managed per-screen "how do I do this" hints -- keyed by
            // screen_key so HowToHint.vue can look its own up; a screen with
            // nothing set here just renders nothing, see ScreenGuide/admin panel
            'guides' => fn () => $shopUser
                ? ScreenGuide::where('is_active', true)->get(['screen_key', 'text_bn', 'text_en'])->keyBy('screen_key')
                : null,
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

    /** Every branch in the same business as $shopUser's own (home) shop, including the main shop itself — null if there's only one (nothing to switch between) or the user isn't an owner. */
    private function branchList(?User $shopUser): ?array
    {
        if (! $shopUser || $shopUser->role !== 'owner') {
            return null;
        }

        $homeShop = Shop::withoutGlobalScopes()->find($shopUser->shop_id);
        if (! $homeShop) {
            return null;
        }

        $rootId = $homeShop->parent_shop_id ?? $homeShop->id;
        $siblings = Shop::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('parent_shop_id', $rootId))
            ->orderBy('id')
            ->get(['id', 'name', 'area', 'parent_shop_id']);

        if ($siblings->count() < 2) {
            return null;
        }

        $activeId = Tenancy::id();

        return $siblings->map(fn (Shop $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'area' => $s->area,
            'active' => $s->id === $activeId,
        ])->values()->all();
    }
}
