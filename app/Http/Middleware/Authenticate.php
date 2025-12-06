<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Redirects or returns JSON when not authenticated.
     */
    protected function unauthenticated($request, array $guards)
    {
        if ($request->expectsJson()) {
            throw new HttpResponseException(
                response()->json(['message' => 'Unauthenticated.'], 401)
            );
        }

        parent::unauthenticated($request, $guards);
    }

    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
