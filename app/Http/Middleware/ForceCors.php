<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('cors');
        $origin = $request->headers->get('Origin');
        $allowedOrigins = collect($config['allowed_origins'] ?? [])->filter()->values();

        $isAllowed = $origin && ($allowedOrigins->contains($origin));

        if ($request->getMethod() === 'OPTIONS') {
            return $this->applyHeaders(
                response('', 204),
                $origin,
                $isAllowed,
                $config
            );
        }

        $response = $next($request);

        return $this->applyHeaders($response, $origin, $isAllowed, $config);
    }

    private function applyHeaders(Response $response, ?string $origin, bool $isAllowed, array $config): Response
    {
        if ($isAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', $config['supports_credentials'] ? 'true' : 'false');
        }

        $allowedMethods = $config['allowed_methods'] ?? ['*'];
        $allowedHeaders = $config['allowed_headers'] ?? ['*'];

        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $allowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));

        return $response;
    }
}
