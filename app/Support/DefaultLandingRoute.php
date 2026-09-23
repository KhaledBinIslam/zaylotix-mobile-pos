<?php

namespace App\Support;

use App\Models\User;

/**
 * Where a shop user lands right after authenticating (fresh login, signup,
 * finishing onboarding, switching branches) — Khaled's explicit "sellers
 * want to start selling the instant the app opens, not go through a
 * dashboard first" request.
 *
 * POS whenever the user can actually reach it (the owner always can; a
 * cashier only if the owner granted the 'pos' permission — without this
 * check, a cashier without it would land on POS just to immediately hit
 * EnsureUserPermission's 403, which is worse than never having sold this
 * feature to them in the first place). Falls back to the always-reachable
 * Home page (see routes/web.php's "always reachable regardless of
 * permission grants" section) for anyone who can't.
 *
 * A restaurant shop's owner still lands here too — PosController::index()
 * itself already redirects a restaurant shop's plain POS visit onward to
 * the Tables screen, so this doesn't need its own restaurant-awareness.
 */
class DefaultLandingRoute
{
    public static function for(User $user): string
    {
        return $user->hasPermission('pos') ? 'app.pos' : 'app.home';
    }
}
