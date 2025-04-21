<?php

namespace Modules\AmeiseModule\Services;

use Modules\AmeiseModule\Entities\AmeiseToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class AmeiseTokenService
{
    /**
     * Liefert garantiert einen gültigen Access‑Token zurück.
     */
    public function getValidAccessToken(): string
    {
        $token = AmeiseToken::first();

        if (! $token) {
            throw new \RuntimeException('Kein OAuth‑Token gefunden – bitte einmalig den OAuth‑Flow durchlaufen.');
        }

        // 60 Sekunden Puffer
        if ($token->expires_at->lessThan(now()->addMinute())) {
            $token = $this->refreshToken($token);
        }

        return $token->access_token;
    }

    /**
     * Tauscht einen abgelaufenen Token gegen einen neuen aus.
     */
    public function refreshToken(AmeiseToken $token): AmeiseToken
    {
        $response = Http::asForm()->post(config('ameisemodule.ameise_oauth_token_url'), [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token->refresh_token,
            'client_id'     => config('ameisemodule.ameise_client_id'),
            'client_secret' => config('ameisemodule.ameise_client_secret'),
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Token‑Refresh fehlgeschlagen: '.$response->body());
        }

        $data = $response->json();

        $token->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            'expires_at'    => Carbon::now()->addSeconds($data['expires_in']),
        ]);

        return $token;
    }
}
