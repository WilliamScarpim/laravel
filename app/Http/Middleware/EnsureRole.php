<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * @param  array<int, string>  $roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! empty($roles) && ! in_array($user->role, $roles, true)) {
            abort(403, 'Acesso não autorizado para este perfil.');
        }

        return $next($request);
    }
}
