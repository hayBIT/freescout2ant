<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Thread;
use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;
use Modules\AmeiseModule\Jobs\ArchiveThreadsJob;

class ArchiveThreads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ameise:archive-threads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archiviere neue Threads ins CRM für alle Nutzer, die die Konversation archiviert haben';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // IDs aller Konversationen, die im CRM archiviert wurden
        $conversationIds = CrmArchive::distinct()->pluck('conversation_id')->toArray();

        if (empty($conversationIds)) {
            return;
        }

        // IDs aller Threads, die erfolgreich archiviert wurden (ohne Fehler)
        $successfulThreadIds = CrmArchiveThread::whereNull('last_error')
            ->distinct()
            ->pluck('thread_id')
            ->toArray();

        // IDs aller Threads, die mindestens einen Fehler haben (Retry nötig)
        $failedThreadIds = CrmArchiveThread::whereNotNull('last_error')
            ->distinct()
            ->pluck('thread_id')
            ->toArray();

        // Threads, die vollständig erledigt sind (erfolgreich UND keine Fehler)
        $fullyDoneThreadIds = array_diff($successfulThreadIds, $failedThreadIds);

        $threads = Thread::select('threads.*')
            ->where('state', Thread::STATE_PUBLISHED)
            ->whereNotIn('threads.id', $fullyDoneThreadIds)
            ->whereIn('threads.conversation_id', $conversationIds)
            ->with(['conversation', 'attachments'])
            ->get();

        foreach ($threads as $thread) {
            $archives = CrmArchive::where('conversation_id', $thread->conversation_id)
                ->groupBy('archived_by')
                ->pluck('archived_by')
                ->toArray();

            $users = User::whereIn('id', $archives)->get();

            foreach ($users as $user) {
                // Nutzer ohne Token-Datei überspringen (nicht verbunden)
                if (!file_exists(storage_path("user_{$user->id}_ant.txt"))) {
                    continue;
                }

                ArchiveThreadsJob::dispatch($thread->conversation, $thread, $user);
            }
        }
    }
}
