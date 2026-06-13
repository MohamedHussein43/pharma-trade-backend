<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveUserMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
     public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
 
        if (! $user || ! $user->is_active) {
            return response()->json([
                'message' => 'Your account is not active. Please wait for admin approval.',
                'status'  => $user?->status ?? 'unknown',
            ], 403);
        }
 
        return $next($request);
    }
}
