<?php
namespace Modules\AmeiseModule\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AmeiseModule\Entities\CrmArchiveAttempt;

class ArchiveThreadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $thread;
    protected $conversation;
    protected $user;
    public $timeout = 120;
    public $tries = 5;

    public function __construct($conversation, $thread, $user)
    {
        $this->conversation = $conversation;
        $this->thread = $thread;
        $this->user = $user;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle()
    {
        config('ameisemodule.ameise_log_status') && \Helper::log('Ameise Cron Log', 'Job Dispatched For Thread ID: '.$this->thread->id.' Conversation ID: '.$this->conversation->id.' User ID: '.$this->user->id.'');

        try {
            $tokenService = new \Modules\AmeiseModule\Services\TokenService('', $this->user->id);
            $apiClient = new \Modules\AmeiseModule\Services\CrmApiClient($tokenService);
            $archiver = new \Modules\AmeiseModule\Services\ConversationArchiver($apiClient);
            $archiver->archiveConversationData($this->conversation, $this->thread, $this->user);
        } catch (\Throwable $e) {
            CrmArchiveAttempt::record([
                'conversation_id' => $this->conversation->id ?? null,
                'thread_id' => $this->thread->id ?? null,
                'user_id' => $this->user->id ?? null,
                'status' => CrmArchiveAttempt::STATUS_FAILED_EXCEPTION,
                'reason' => substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e)
    {
        CrmArchiveAttempt::record([
            'conversation_id' => $this->conversation->id ?? null,
            'thread_id' => $this->thread->id ?? null,
            'user_id' => $this->user->id ?? null,
            'status' => CrmArchiveAttempt::STATUS_FAILED_EXCEPTION,
            'reason' => 'Final failure after ' . $this->tries . ' attempts: ' . substr($e->getMessage(), 0, 1000),
        ]);
    }
}
