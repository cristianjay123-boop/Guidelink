<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentPasswordIsChanged
{
    /**
     * Require provisioned students to replace temporary passwords before continuing.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->user()?->must_change_password &&
            ! in_array($request->route()?->getName(), ['security.edit', 'user-password.update'], true)
        ) {
            return to_route('security.edit');
        }

        return $next($request);
    }
}
