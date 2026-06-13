<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
 
        if (! $user || ! in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'You do not have permission to access this resource.',
            ], 403);
        }
 
        return $next($request);
    }
}
