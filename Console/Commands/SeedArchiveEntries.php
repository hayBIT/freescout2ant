<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Thread;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Console\Concerns\WritesReport;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveEntry;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

/**
 * Legt für bereits archivierte Threads die fehlenden Einträge an.
 *
 * Bis zu dieser Ausbaustufe hat FreeScout nur vermerkt, *dass* ein Thread
 * archiviert wurde. Dieser Befehl rekonstruiert daraus die einzelnen
 * Archiveinträge — Nachricht und je Anhang einen — damit der Resolver
 * anschließend ihre UUIDs ermitteln kann.
 *
 * Er ruft dabei keine API auf und legt nichts in der Ameise an.
 */
class SeedArchiveEntries extends Command
{
    use WritesReport;

    private const MAX_SUBJECT_LENGTH = 128;

    protected $signature = 'ameise:seed-archive-entries
        {--conversation= : Nur diese Konversation}
        {--limit=1000 : Höchstzahl der Threads je Lauf}
        {--dry-run : Nur zeigen, was angelegt würde}
        {--out= : Die vollständige Ausgabe zusätzlich in diese Datei schreiben}';

    protected $description = 'Legt für bereits archivierte Threads die Archiveinträge lokal an';

    public function handle()
    {
        $exitCode = $this->seed();
        $this->writeReport();

        return $exitCode;
    }

    private function seed()
    {
        $query = CrmArchiveThread::orderBy('id');
        if ($conversationId = $this->option('conversation')) {
            $query->where('conversation_id', $conversationId);
        }
        $archiveThreads = $query->limit((int) $this->option('limit'))->get();

        if ($archiveThreads->isEmpty()) {
            $this->info('Keine archivierten Threads gefunden.');
            return 0;
        }

        $this->line('Archivierte Threads: ' . $archiveThreads->count());
        if ($this->option('dry-run')) {
            $this->comment('Probelauf — es wird nichts gespeichert.');
        }
        $this->line('');

        $created = 0;
        $skipped = 0;
        $incomplete = 0;

        foreach ($archiveThreads as $archiveThread) {
            $archive = CrmArchive::find($archiveThread->crm_archive_id);
            $thread = Thread::with('attachments')->find($archiveThread->thread_id);

            if (!$archive || !$thread || !$thread->conversation) {
                $incomplete++;
                continue;
            }

            $rows = $this->rowsFor($archive, $thread);
            foreach ($rows as $row) {
                if ($this->exists($row)) {
                    $skipped++;
                    continue;
                }
                if (!$this->option('dry-run')) {
                    CrmArchiveEntry::create($row + ['sync_state' => CrmArchiveEntry::STATE_PENDING]);
                }
                $created++;
            }
        }

        $this->line('  angelegt:        ' . $created);
        $this->line('  bereits bekannt: ' . $skipped);
        if ($incomplete > 0) {
            $this->line('  übersprungen:    ' . $incomplete . ' (Thread oder Zuordnung nicht mehr vorhanden)');
        }

        if ($created > 0 && !$this->option('dry-run')) {
            $this->line('');
            $this->info('Weiter mit: php artisan ameise:resolve-archive-ids');
        }

        return 0;
    }

    /**
     * Ein Datensatz für die Nachricht, je Anhang ein weiterer — so, wie die
     * Archivierung sie damals erzeugt hat.
     */
    private function rowsFor(CrmArchive $archive, Thread $thread): array
    {
        $conversation = $thread->conversation;
        $date = Carbon::parse($thread->created_at)->setTimezone('UTC');

        $base = [
            'crm_archive_id' => $archive->id,
            'conversation_id' => $conversation->id,
            'thread_id' => $thread->id,
            'archived_by' => $archive->archived_by,
            'customer_id' => (string) $archive->crm_user_id,
            'entry_date' => $date,
        ];

        $rows = [];

        // Bei #scanonly wurde nur der Anhang archiviert, nicht die Nachricht.
        if (stripos($conversation->subject ?? '', '#scanonly') === false) {
            $rows[] = $base + [
                'attachment_id' => null,
                'kind' => CrmArchiveEntry::KIND_THREAD,
                'subject' => $this->conversationSubject($conversation),
                'entry_type' => $conversation->type == \App\Conversation::TYPE_EMAIL ? 'email' : 'telefon',
            ];
        }

        foreach ($thread->attachments as $attachment) {
            $rows[] = $base + [
                'attachment_id' => $attachment->id,
                'kind' => CrmArchiveEntry::KIND_ATTACHMENT,
                'subject' => $attachment->file_name,
                'entry_type' => 'dokument',
            ];
        }

        return $rows;
    }

    private function conversationSubject($conversation): string
    {
        $subject = trim((string) ($conversation->subject ?? ''));
        if ($subject === '') {
            $subject = '(Kein Betreff)';
        }

        return mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH);
    }

    private function exists(array $row): bool
    {
        return CrmArchiveEntry::where('conversation_id', $row['conversation_id'])
            ->where('thread_id', $row['thread_id'])
            ->where('attachment_id', $row['attachment_id'])
            ->where('customer_id', $row['customer_id'])
            ->exists();
    }
}
