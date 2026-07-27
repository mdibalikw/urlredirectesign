<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMekariToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Unauthorized: Token is missing'], 401);
        }

        // Validasi token ke server Mekari
        $mekariAuthUrl = env('MEKARI_AUTH_URL');
        
        if (!$mekariAuthUrl) {
            return response()->json(['error' => 'Internal Server Error: Mekari Auth URL not configured'], 500);
        }

        // Anda bisa menyesuaikan method HTTP (GET/POST) dan struktur request/response sesuai dokumentasi Mekari API
        $response = \Illuminate\Support\Facades\Http::withToken($token)->get($mekariAuthUrl);

        if ($response->failed()) {
            return response()->json(['error' => 'Unauthorized: Invalid token'], 401);
        }

        return $next($request);
    }
}
