<?php

namespace App\Mcp;

use App\Models\User;
use Laravel\Mcp\Request;

final class Auth
{
    /**
     * Resolve the acting user for an MCP request. Falls back to the user
     * identified by the MCP_USER_EMAIL env var when the request itself
     * has no authenticated user (typically a local stdio server).
     */
    public static function resolve(Request $request): ?User
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user;
        }

        $email = config('specify.mcp.user_email');
        if (! $email) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }
}
