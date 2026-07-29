<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Conversation;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Jobs\AutoAssignConversationJob;
use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\CustomerMatcher;
use Modules\AmeiseModule\Services\TokenService;

class AutoAssignConversations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ameise:auto-assign {--dry-run : Nur auswerten und protokollieren, nichts archivieren} {--limit=50 : Maximale Anzahl Konversationen pro Lauf}';

    /**
     * @var string
     */
    protected $description = 'Ordnet noch nicht archivierte Konversationen automatisch einem Ameise-Kunden zu und archiviert sie';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!config('ameisemodule.ameise_auto_assign') && !$dryRun) {
            $this->info('Automatische Zuordnung ist deaktiviert (AMEISE_AUTO_ASSIGN).');

            return 0;
        }

        $serviceUser = TokenService::getServiceUser();
        if (!$serviceUser) {
            $this->error('Kein verbundener Ameise-Service-Nutzer konfiguriert (AMEISE_SERVICE_USER_ID).');
            \Helper::log('ameise_auto_assign', 'Auto-Zuordnung übersprungen: kein verbundener Service-Nutzer konfiguriert.');

            return 1;
        }

        $conversations = $this->pendingConversations();
        if ($conversations->isEmpty()) {
            $this->info('Keine offenen Konversationen für die automatische Zuordnung.');

            return 0;
        }

        $this->info($conversations->count() . ' Konversation(en) werden geprüft' . ($dryRun ? ' (Dry-Run)' : '') . '.');

        if ($dryRun) {
            $this->reportDryRun($conversations, $serviceUser);

            return 0;
        }

        foreach ($conversations as $conversation) {
            AutoAssignConversationJob::dispatch($conversation, $serviceUser);
        }

        return 0;
    }

    /**
     * Konversationen ohne jede Ameise-Zuordnung, die für eine Automatik in Frage kommen.
     */
    private function pendingConversations()
    {
        $maxAgeDays = (int) config('ameisemodule.ameise_auto_assign_max_age_days', 30);

        $query = Conversation::whereNotIn('id', function ($sub) {
                $sub->select('conversation_id')->from('crm_archives');
            })
            ->where('state', Conversation::STATE_PUBLISHED)
            ->where('status', '!=', Conversation::STATUS_SPAM)
            ->with('threads')
            ->orderBy('id', 'desc')
            ->limit(max(1, (int) $this->option('limit')));

        if ($maxAgeDays > 0) {
            $query->where('created_at', '>=', now()->subDays($maxAgeDays));
        }

        $mailboxIds = $this->mailboxIds();
        if (!empty($mailboxIds)) {
            $query->whereIn('mailbox_id', $mailboxIds);
        }

        return $query->get();
    }

    /**
     * @return int[]
     */
    private function mailboxIds(): array
    {
        $configured = (string) config('ameisemodule.ameise_auto_assign_mailboxes', '');
        if (trim($configured) === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $configured))));
    }

    /**
     * Wertet aus, was zugeordnet würde – ohne Schreibvorgänge im CRM.
     */
    private function reportDryRun($conversations, $serviceUser)
    {
        $tokenService = new TokenService('', $serviceUser->id);
        $matcher = new CustomerMatcher(new CrmApiClient($tokenService));

        $unique = $ambiguous = $none = 0;

        foreach ($conversations as $conversation) {
            $match = $matcher->match($conversation);

            if (!empty($match['redirect'])) {
                $this->error('Ameise-Token des Service-Nutzers ist ungültig – erneute Anmeldung nötig.');

                return;
            }

            $count = count($match['candidates']);
            if ($count === 1) {
                $unique++;
                $candidate = $match['candidates'][0];
                $this->line(sprintf(
                    '  #%d "%s" => %s (%s, Quelle: %s)',
                    $conversation->id,
                    mb_strimwidth((string) $conversation->subject, 0, 40, '…'),
                    $candidate['Id'],
                    $candidate['Text'] ?? '',
                    $match['source']
                ));
            } elseif ($count > 1) {
                $ambiguous++;
                $this->line(sprintf('  #%d mehrdeutig: %d Kandidaten', $conversation->id, $count));
            } else {
                $none++;
                $this->line(sprintf('  #%d kein Treffer', $conversation->id));
            }
        }

        $summary = sprintf('Dry-Run: %d eindeutig, %d mehrdeutig, %d ohne Treffer.', $unique, $ambiguous, $none);
        $this->info($summary);
        \Helper::log('ameise_auto_assign', $summary);
    }
}
