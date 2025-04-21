<?php

namespace Modules\AmeiseModule\Services;

use Illuminate\Support\Facades\Http;

class AmeiseApiClient
{
    public function __construct(
        protected AmeiseTokenService $tokenService
    ) {}

    /**
     * Universelle Methode für API‑Calls (GET, POST, …).
     *
     * @param  string  $method  HTTP‑Methode (GET|POST|PUT|PATCH|DELETE)
     * @param  string  $uri     Endpunkt relativ zur Base‑URI
     * @param  array   $options query|json je nach Methode
     */
    public function request(string $method, string $uri, array $options = [])
    {
        $token = $this->tokenService->getValidAccessToken();

        $http = Http::withOptions([
            'base_uri' => rtrim(config('ameisemodule.ameise_base_uri'), '/').'/',
        ])->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);

        return match (strtoupper($method)) {
            'GET'     => $http->get($uri, $options['query'] ?? []),
            'POST'    => $http->post($uri, $options['json'] ?? []),
            'PUT'     => $http->put($uri, $options['json'] ?? []),
            'PATCH'   => $http->patch($uri, $options['json'] ?? []),
            'DELETE'  => $http->delete($uri, $options['query'] ?? []),
            default   => throw new \InvalidArgumentException("Unsupported method {$method}"),
        };
    }
}
