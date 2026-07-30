<?php

namespace Modules\AmeiseModule\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AutoAssignConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $conversation;
    protected $user;
    public $timeout = 300;

    /**
     * @param \App\Conversation $conversation
     * @param \App\User         $user Service-Nutzer, dessen Ameise-Zugang verwendet wird
     */
    public function __construct($conversation, $user)
    {
        $this->conversation = $conversation;
        $this->user = $user;
    }

    public function handle()
    {
        config('ameisemodule.ameise_log_status') && \Helper::log(
            'ameise_auto_assign',
            'Auto-Zuordnung gestartet für Konversation ID: ' . $this->conversation->id . ' als Nutzer ID: ' . $this->user->id
        );

        $tokenService = new \Modules\AmeiseModule\Services\TokenService('', $this->user->id);
        $apiClient = new \Modules\AmeiseModule\Services\CrmApiClient($tokenService);
        $archiver = new \Modules\AmeiseModule\Services\ConversationArchiver($apiClient);
        $archiver->autoAssignConversation($this->conversation, $this->user);
    }
}
