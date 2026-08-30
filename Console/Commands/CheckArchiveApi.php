<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Services\ArchiveApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Prüft, ob Host und OAuth-Scope für die Archive-API stimmen, bevor Funktionen
 * darauf aufgebaut werden.
 */
class CheckArchiveApi extends Command
{
    protected $signature = 'ameise:archive-api-check {--user= : ID des FreeScout-Benutzers, dessen Ameise-Verbindung genutzt wird}';

    protected $description = 'Prüft Erreichbarkeit und Berechtigung der Archive-API';

    public function handle()
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
        $this->line('');
        $this->line('Rufe GET ' . $client->getBaseUrl() . 'contracts/tags auf …');

        $tags = $client->getContractsTags();

        if ($client->getLastError() !== null) {
            $this->error('Fehlgeschlagen: ' . $client->getLastError());
            if ($client->getLastStatusCode() !== null) {
                $this->line('HTTP-Status: ' . $client->getLastStatusCode());
            }
            if ($client->getLastStatusCode() === 403) {
                $this->line('Ein 403 deutet darauf hin, dass der OAuth-Scope die Archive-API nicht einschließt.');
            }
            return 1;
        }

        $this->info('Erfolgreich. ' . count($tags) . ' Tag(s) gelesen.');
        if (!empty($tags)) {
            $this->line('Beispiele: ' . implode(', ', array_slice($tags, 0, 5)));
        }

        return 0;
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
