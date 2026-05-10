<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('specify.mcp.api_key');

        if (! $configured || $request->bearerToken() !== $configured) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $email = config('specify.mcp.user_email');
        $user = $email ? User::query()->where('email', $email)->first() : null;

        if (! $user) {
            return response()->json(['error' => 'No acting user configured. Set MCP_USER_EMAIL.'], 403);
        }

        auth()->setUser($user);

        return $next($request);
    }
}
