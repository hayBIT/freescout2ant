<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Thread;
use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

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
     * Maximum number of threads to process per run to avoid long-running processes.
     */
    protected const BATCH_LIMIT = 50;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // IDs aller archivierten Konversationen
        $conversationIds = CrmArchiveThread::distinct()->pluck('conversation_id')->toArray();

        if (empty($conversationIds)) {
            return;
        }

        // IDs aller Threads, die bereits archiviert wurden
        $threadIds = CrmArchiveThread::distinct()->pluck('thread_id')->toArray();

        $threads = Thread::select('threads.*')
            ->where('state', Thread::STATE_PUBLISHED)
            ->whereNotIn('threads.id', $threadIds)
            ->whereIn('threads.conversation_id', $conversationIds)
            ->with(['conversation', 'attachments'])
            ->limit(self::BATCH_LIMIT)
            ->get();

        foreach ($threads as $thread) {
            $archives = CrmArchive::where('conversation_id', $thread->conversation_id)
                ->groupBy('archived_by')
                ->pluck('archived_by')
                ->toArray();

            $users = User::whereIn('id', $archives)->get();

            foreach ($users as $user) {
                try {
                    $tokenService = new \Modules\AmeiseModule\Services\TokenService('', $user->id);
                    $apiClient = new \Modules\AmeiseModule\Services\CrmApiClient($tokenService);
                    $archiver = new \Modules\AmeiseModule\Services\ConversationArchiver($apiClient);
                    $archiver->archiveConversationData($thread->conversation, $thread, $user);
                } catch (\Exception $e) {
                    \Helper::log('Ameise Cron Log', 'Failed to archive Thread ID: '.$thread->id.' for User ID: '.$user->id.': '.$e->getMessage());
                }
            }
        }
    }
}
