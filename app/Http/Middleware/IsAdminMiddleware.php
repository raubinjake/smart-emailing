<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to users flagged is_admin.
 */
class IsAdminMiddleware
{
    /**
     * @param  Request  $request  the incoming request
     * @param  Closure  $next     the next handler in the stack
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            abort(403, 'Administrator access required.');
        }

        return $next($request);
    }
}
