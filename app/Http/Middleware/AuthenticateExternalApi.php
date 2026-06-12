<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateExternalApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('services.third_party_api.key');
        $providedKey = $request->header('X-API-Key');

        if (! is_string($configuredKey) || $configuredKey === '') {
            abort(503, 'External API key is not configured.');
        }

        if (! is_string($providedKey) || ! hash_equals($configuredKey, $providedKey)) {
            abort(401, 'Invalid API key.');
        }

        return $next($request);
    }
}
