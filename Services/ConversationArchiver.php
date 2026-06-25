<?php

namespace Modules\AmeiseModule\Services;

use App\Conversation;
use App\Thread;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;
use Modules\AmeiseModule\Services\Archive\ArchiveWriterFactory;

class ConversationArchiver
{
    private $apiClient;
    private $tokenService;

    public function __construct(CrmApiClient $apiClient, TokenService $tokenService)
    {
        $this->apiClient = $apiClient;
        $this->tokenService = $tokenService;
    }

    public function shouldArchiveThread($conversation, $thread)
    {
        // Never archive system line items (status changes, assignments, etc.).
        // They carry no real content, so the CRM rejects them and the whole
        // archive run would be reported as failed (dialog stays open).
        if ($thread->type == Thread::TYPE_LINEITEM) {
            return false;
        }

        // Skip threads that are not actually sent yet (e.g. auto-saved drafts).
        // Archiving an unsent draft pushes incomplete content to the CRM and may
        // fail, which would otherwise keep the archive dialog from closing.
        if (isset($thread->state) && $thread->state != Thread::STATE_PUBLISHED) {
            return false;
        }

        if ($thread->type !== Thread::TYPE_NOTE) {
            return true;
        }

        if ($conversation->type != Conversation::TYPE_PHONE) {
            return false;
        }

        return $this->isFirstConversationThread($conversation, $thread);
    }

    private function isFirstConversationThread($conversation, $thread)
    {
        $firstThreadId = Thread::where('conversation_id', $conversation->id)->orderBy('id', 'asc')->value('id');

        return !is_null($firstThreadId) && (int) $thread->id === (int) $firstThreadId;
    }

    public function isScanOnly($conversation)
    {
        return stripos($conversation->subject ?? '', '#scanonly') !== false;
    }

    public function archiveConversationData($conversation, $thread = null, $user = null)
    {
        $thread = $thread ?? $conversation->getLastThread();
        $user = $user ?? auth()->user();
        if (!$this->shouldArchiveThread($conversation, $thread)) {
            return;
        }

        $writer = ArchiveWriterFactory::make($this->apiClient, $this->tokenService);
        $scanOnly = $this->isScanOnly($conversation);

        $crmArchives = CrmArchive::where('conversation_id', $conversation->id)->get();
        if (count($crmArchives) > 0) {
            foreach ($crmArchives as $crmArchive) {
                $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crmArchive->id)->where('thread_id', $thread->id)->first();
                if (!$isArchiveThread) {
                    $contracts = !empty($crmArchive->contracts) ? json_decode($crmArchive->contracts, true) : [];
                    $divisions = !empty($crmArchive->divisions) ? json_decode($crmArchive->divisions, true) : [];
                    $archived = $scanOnly ? true : $writer->archiveText($conversation, $thread, $crmArchive->crm_user_id, $contracts, $divisions, $user);
                    $attachmentsArchived = $archived ? $writer->archiveAttachments($conversation, $thread, $crmArchive->crm_user_id, $contracts, $divisions, $user) : false;
                    if ($archived && (!$scanOnly || $attachmentsArchived)) {
                        CrmArchiveThread::create(['crm_archive_id' => $crmArchive->id, 'thread_id' => $thread->id, 'conversation_id' => $conversation->id]);
                    }
                }
            }
        } else {
            // No archive record yet: resolve the customer by e-mail. The Stocks
            // API cannot search by e-mail, so this lookup always uses the legacy
            // MitarbeiterWebservice client (hybrid fallback).
            $response = $this->apiClient->fetchUserByEmail($conversation->customer_email);
            if (count($response) == 1) {
                $crm_user_id = $response[0]['Id'];
                $archived = $scanOnly ? true : $writer->archiveText($conversation, $thread, $crm_user_id, [], [], $user);
                $attachmentsArchived = $archived ? $writer->archiveAttachments($conversation, $thread, $crm_user_id, [], [], $user) : false;
                if ($archived && (!$scanOnly || $attachmentsArchived)) {
                    $crm_archive = CrmArchive::firstOrNew(['conversation_id' => $conversation->id, 'crm_user_id' => $crm_user_id, 'archived_by' => $user->id]);
                    $crm_archive->crm_user = json_encode(['id' => $crm_user_id, 'text' => $response[0]['Text']]);
                    $crm_archive->contracts = null;
                    $crm_archive->divisions = null;
                    $crm_archive->save();
                    CrmArchiveThread::create(['crm_archive_id' => $crm_archive->id, 'thread_id' => $thread->id, 'conversation_id' => $conversation->id]);
                }
            }
        }
    }
}
