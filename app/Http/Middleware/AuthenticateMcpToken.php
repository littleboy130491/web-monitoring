<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    /**
     * Require a bearer token matching MCP_TOKEN. The HTTP MCP endpoint is
     * disabled entirely when no token is configured.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('monitoring.mcp_token');

        if ($token === '') {
            abort(404);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
