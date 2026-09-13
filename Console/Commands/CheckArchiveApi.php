<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Console\Concerns\WritesReport;
use Modules\AmeiseModule\Services\ArchiveApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Prüft, ob Host und OAuth-Scope für die Archive-API stimmen, bevor Funktionen
 * darauf aufgebaut werden.
 */
class CheckArchiveApi extends Command
{
    use WritesReport;

    protected $signature = 'ameise:archive-api-check
        {--user= : ID des FreeScout-Benutzers, dessen Ameise-Verbindung genutzt wird}
        {--customer= : Kundennummer, um zusätzlich die kundenbezogenen Endpunkte zu prüfen}
        {--out= : Die vollständige Ausgabe zusätzlich in diese Datei schreiben}';

    protected $description = 'Prüft Erreichbarkeit und Berechtigung der Archive-API';

    public function handle()
    {
        $exitCode = $this->check();
        $this->writeReport();

        return $exitCode;
    }

    private function check()
    {
        $userId = $this->option('user') ?: $this->firstConnectedUserId();
        if (!$userId) {
            $this->error('Kein Benutzer mit bestehender Ameise-Verbindung gefunden. Bitte in FreeScout mit der Ameise verbinden oder --user angeben.');
            return 1;
        }

        $user = User::find($userId);
        if (!$user) {
            $this->error('Benutzer ' . $userId . ' existiert nicht.');
            return 1;
        }
        if (!file_exists(storage_path('user_' . $userId . '_ant.txt'))) {
            $this->error('Benutzer ' . $userId . ' ist nicht mit der Ameise verbunden.');
            return 1;
        }

        $client = new ArchiveApiClient(new TokenService('', $userId));

        $this->line('Modus:    ' . config('ameisemodule.ameise_mode'));
        $this->line('Benutzer: ' . $user->getFullName() . ' (' . $userId . ')');

        if (!$client->isConfigured()) {
            $this->error('Keine URL für die Archive-API hinterlegt.');
            $this->line('Bitte unter Einstellungen → Ameise eintragen oder AMEISE_ARCHIVE_API_URL in der .env setzen.');
            return 1;
        }
        $this->line('Host:     ' . $client->getBaseUrl());

        $this->showScopes($userId);

        $customerId = $this->option('customer');
        $probes = [
            'contracts/tags' => function () use ($client) {
                return $client->getContractsTags();
            },
            'customers/tags' => function () use ($client) {
                return $client->getCustomersTags();
            },
        ];
        if ($customerId) {
            $probes['customers/' . $customerId . '/tags'] = function () use ($client, $customerId) {
                return $client->getCustomerTags($customerId);
            };
            $probes['customers/' . $customerId . '/archive-entries'] = function () use ($client, $customerId) {
                return $client->listArchiveEntries($customerId, ['pageSize' => 1]);
            };
        }

        $this->line('');
        $this->line('Endpunkte:');

        $statuses = [];
        $okCount = 0;
        foreach ($probes as $label => $call) {
            $call();
            $status = $client->getLastStatusCode();
            $statuses[] = $status;
            $error = $client->getLastError();

            if ($error === null) {
                $okCount++;
                $this->line('  <info>OK</info>    GET ' . $label . ' (' . $status . ')');
                continue;
            }

            $this->line('  <error>FEHL</error>  GET ' . $label . ' (' . ($status ?: 'kein Status') . ') — ' . $error);
            $body = trim((string) $client->getLastResponseBody());
            if ($body !== '') {
                $this->line('        Antwort: ' . mb_substr($body, 0, 500));
            }
        }

        $this->line('');

        if ($okCount === count($probes)) {
            $this->info('Die Archive-API ist erreichbar und alle geprüften Endpunkte antworten.');
            return 0;
        }

        if ($okCount > 0) {
            $this->warn('Ein Teil der Endpunkte antwortet, ein anderer nicht — der Scope greift, aber nicht überall.');
            return 0;
        }

        if (in_array(403, $statuses, true)) {
            $this->error('Alle Aufrufe enden mit 403 — der Zugriff wird verweigert.');
            $this->line('');
            $this->line('Häufigste Ursache: Das gespeicherte Token trägt den Scope für die Archive-API nicht.');
            $this->line('Der Scope wird beim Verbinden angefragt und ist danach im Token festgeschrieben —');
            $this->line('eine Erweiterung von AMEISE_SCOPE wirkt erst nach einer neuen Anmeldung.');
            $this->line('');
            $this->line('Vorgehen:');
            $this->line('  1. AMEISE_SCOPE um den Scope der Archive-API ergänzen.');
            $this->line('  2. Die Verbindung des Benutzers trennen (Token-Datei storage/user_' . $userId . '_ant.txt entfernen).');
            $this->line('  3. In FreeScout neu mit der Ameise verbinden und diesen Befehl erneut ausführen.');
            return 1;
        }

        $this->error('Kein Aufruf war erfolgreich. Details stehen oben bei den Endpunkten.');
        return 1;
    }

    /**
     * Zeigt, welcher Scope angefragt wurde und welcher tatsächlich im Token steht.
     */
    private function showScopes($userId)
    {
        $this->line('Scope (angefragt): ' . config('ameisemodule.ameise_scope'));

        $tokens = json_decode((string) @file_get_contents(storage_path('user_' . $userId . '_ant.txt')), true);
        if (!is_array($tokens)) {
            return;
        }

        if (!empty($tokens['scope'])) {
            $this->line('Scope (im Token):  ' . $tokens['scope']);
        }

        $claims = $this->decodeJwtPayload($tokens['access_token'] ?? '');
        if ($claims === null) {
            return;
        }
        foreach (['scope', 'scp', 'aud'] as $claim) {
            if (!isset($claims[$claim])) {
                continue;
            }
            $value = is_array($claims[$claim]) ? implode(' ', $claims[$claim]) : $claims[$claim];
            $this->line('Token-Claim ' . $claim . ': ' . $value);
        }
    }

    /**
     * Liest die Nutzdaten eines JWT, ohne die Signatur zu prüfen — hier geht es
     * nur um die Diagnose, nicht um Vertrauen in den Inhalt.
     */
    private function decodeJwtPayload($token)
    {
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return null;
        }

        $payload = explode('.', $token)[1];
        $decoded = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4), true);
        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : null;
    }

    /**
     * Erster Benutzer, für den eine Token-Datei existiert.
     */
    private function firstConnectedUserId()
    {
        foreach (glob(storage_path('user_*_ant.txt')) ?: [] as $file) {
            if (preg_match('/user_(\d+)_ant\.txt$/', $file, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
