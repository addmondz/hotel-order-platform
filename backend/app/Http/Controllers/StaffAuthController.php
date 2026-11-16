<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class StaffAuthController extends Controller
{
    public function csrf(): JsonResponse
    {
        return response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request): JsonResponse
    {
        if ($request->user()) {
            return response()->json($this->summary($request))->header('Cache-Control', 'no-store');
        }

        $input = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:72'],
        ]);
        $username = Str::lower(trim($input['username']));
        if (strlen($input['password']) > 72 || str_contains($input['password'], "\0")) {
            return response()->json(['error' => 'Invalid username or password.'], 401);
        }

        $account = 'orderdesk.login.account:'.hash('sha256', $username.'|'.$request->ip());
        $network = 'orderdesk.login.network:'.hash('sha256', (string) $request->ip());

        if (RateLimiter::tooManyAttempts($account, 5) || RateLimiter::tooManyAttempts($network, 30)) {
            $retryAfter = max(RateLimiter::availableIn($account), RateLimiter::availableIn($network));

            return response()->json(['error' => 'Too many sign-in attempts. Try again later.'], 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($account, 900);
        RateLimiter::hit($network, 900);
        if (! Auth::attempt(['username' => $username, 'password' => $input['password']])) {
            return response()->json(['error' => 'Invalid username or password.'], 401);
        }

        RateLimiter::clear($account);
        $request->session()->regenerate();

        return response()->json($this->summary($request))->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->summary($request))->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    private function summary(Request $request): array
    {
        return ['id' => $request->user()->id, 'username' => $request->user()->username];
    }
}
