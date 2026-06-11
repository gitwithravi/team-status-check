<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'today' => today(config('app.timezone'))->toDateString(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'active' => true], $request->boolean('remember'))) {
            return response()->json([
                'message' => 'The provided credentials are invalid or the account is inactive.',
                'errors' => [
                    'email' => ['The provided credentials are invalid or the account is inactive.'],
                ],
            ], 422);
        }

        $request->session()->regenerate();

        return $this->user($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}
