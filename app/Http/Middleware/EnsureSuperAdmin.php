<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Gates the most destructive/platform-wide admin actions (deleting a shop, managing business types/features/other admins) behind the super_admin role — a support admin can view and impersonate but not reshape the platform. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->user()?->isSuperAdmin()) {
            abort(403, 'Only a super admin can do this.');
        }

        return $next($request);
    }
}
