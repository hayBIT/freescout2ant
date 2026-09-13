<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Trennt die Ameise-Verbindung eines Benutzers, indem die Token-Datei entfernt wird.
 *
 * Nötig nach einer Änderung des OAuth-Scope: der Scope steht im ausgestellten
 * Token fest, und der Refresh verlängert genau dieses Token. Erst eine neue
 * Anmeldung fragt den geänderten Scope an.
 */
class DisconnectAmeise extends Command
{
    protected $signature = 'ameise:disconnect
        {--user= : ID des FreeScout-Benutzers}
        {--all : Verbindungen aller Benutzer trennen}';

    protected $description = 'Trennt die Ameise-Verbindung, damit beim nächsten Verbinden ein neues Token entsteht';

    public function handle()
    {
        $userIds = $this->option('all') ? $this->connectedUserIds() : array_filter([$this->option('user')]);

        if (empty($userIds)) {
            $this->error('Bitte --user=<ID> oder --all angeben.');
            $connected = $this->connectedUserIds();
            if (!empty($connected)) {
                $this->line('Verbunden sind derzeit: ' . implode(', ', $connected));
            }
            return 1;
        }

        $disconnected = 0;
        foreach ($userIds as $userId) {
            $name = optional(User::find($userId))->getFullName() ?: 'unbekannt';
            if ((new TokenService('', $userId))->disconnectAmeise()) {
                $this->info('Verbindung getrennt: ' . $name . ' (' . $userId . ')');
                $disconnected++;
            } else {
                $this->warn('Keine Verbindung vorhanden: ' . $name . ' (' . $userId . ')');
            }
        }

        if ($disconnected > 0) {
            $this->line('');
            $this->line('Die betroffenen Benutzer verbinden sich in FreeScout über das rote Ameisen-Symbol neu.');
        }

        return 0;
    }

    private function connectedUserIds()
    {
        $ids = [];
        foreach (glob(storage_path('user_*_ant.txt')) ?: [] as $file) {
            if (preg_match('/user_(\d+)_ant\.txt$/', $file, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }

        return $ids;
    }
}
