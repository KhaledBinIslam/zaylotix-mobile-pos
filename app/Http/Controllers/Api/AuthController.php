<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        // Same per-account+IP throttle as the web logins — this endpoint is
        // the mobile/PWA wrapper's only door in, and without its own limiter
        // it'd be a wide-open brute-force target even though the web logins
        // are protected.
        $key = Str::transliterate(Str::lower($data['login']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'login' => "Too many attempts. Try again in {$seconds}s.",
            ]);
        }

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user = User::where($field, $data['login'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }

        if (! $user->shop || ! $user->shop->isActive()) {
            throw ValidationException::withMessages(['login' => 'Subscription inactive. Contact admin.']);
        }

        RateLimiter::clear($key);
        $token = $user->createToken($data['device_name'])->plainTextToken;

        // never the raw shop model, see Shop::toArrayForUser()
        return response()->json(['token' => $token, 'user' => $user, 'shop' => $user->shop?->toArrayForUser($user)]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        // never the raw shop model, see Shop::toArrayForUser()
        return response()->json(['user' => $user, 'shop' => $user->shop?->toArrayForUser($user)]);
    }
}
