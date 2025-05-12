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
        // IDs aller archivierten Konversationen
        $conversationIds = CrmArchiveThread::distinct()->pluck('conversation_id')->toArray();
        // IDs aller Threads, die bereits archiviert wurden
        $threadIds = CrmArchiveThread::distinct()->pluck('thread_id')->toArray();

        $threads = Thread::select('threads.*')
            ->where('state', Thread::STATE_PUBLISHED)
            ->whereNotIn('threads.id', $threadIds)
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
                // Dispatch des Jobs
                ArchiveThreadsJob::dispatch($thread->conversation, $thread, $user);
            }
        }
    }
}
