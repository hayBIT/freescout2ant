<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Thread;
use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveAttempt;
use Modules\AmeiseModule\Entities\CrmArchiveThread;
use Modules\AmeiseModule\Jobs\ArchiveThreadsJob;

class ArchiveThreads extends Command
{
    protected $signature = 'ameise:archive-threads';

    protected $description = 'Archiviere neue Threads ins CRM für alle Nutzer, die die Konversation archiviert haben';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $conversationIds = CrmArchiveThread::distinct()->pluck('conversation_id')->toArray();
        $threadIds = CrmArchiveThread::distinct()->pluck('thread_id')->toArray();

        $threads = Thread::select('threads.*')
            ->where('state', Thread::STATE_PUBLISHED)
            ->whereNotIn('threads.id', $threadIds)
            ->whereIn('threads.conversation_id', $conversationIds)
            ->with(['conversation', 'attachments'])
            ->get();

        $dispatched = 0;
        foreach ($threads as $thread) {
            $archives = CrmArchive::where('conversation_id', $thread->conversation_id)
                ->groupBy('archived_by')
                ->pluck('archived_by')
                ->toArray();

            $users = User::whereIn('id', $archives)->get();

            foreach ($users as $user) {
                ArchiveThreadsJob::dispatch($thread->conversation, $thread, $user);
                $dispatched++;
            }
        }

        $pendingFailures = CrmArchiveAttempt::whereIn('status', CrmArchiveAttempt::FAILURE_STATUSES)
            ->whereNull('resolved_at')
            ->count();

        $this->info(sprintf(
            'ameise:archive-threads dispatched=%d threads=%d unresolved_failures=%d',
            $dispatched,
            $threads->count(),
            $pendingFailures
        ));
    }
}
