<?php

namespace Modules\AmeiseModule\Services\Archive;

use App\Conversation;
use Carbon\Carbon;
use Modules\AmeiseModule\Services\CrmApiClient;

/**
 * Legacy archive strategy: writes archive entries through the
 * MitarbeiterWebservice REST API (header based, one request per attachment).
 */
class MitarbeiterWebserviceWriter implements ArchiveWriterInterface
{
    private $apiClient;

    public function __construct(CrmApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    public function archiveText($conversation, $thread, $crmUserId, $contracts, $divisions, $user = null): bool
    {
        return $this->apiClient->archiveConversation(
            $this->buildConversationData($conversation, $thread, $crmUserId, $contracts, $divisions, $user)
        );
    }

    public function archiveAttachments($conversation, $thread, $crmUserId, $contracts, $divisions, $user = null): bool
    {
        $allAttachments = $thread->attachments;
        if ($allAttachments->count() === 0) {
            return true;
        }

        $user = $user ?? auth()->user();
        $userTimezone = $user->timezone;
        $conversationData = $this->buildConversationData($conversation, $thread, $crmUserId, $contracts, $divisions, $user);
        $allArchived = true;

        foreach ($allAttachments as $attachment) {
            $content = ArchiveContentHelper::attachmentContent($attachment);
            if ($content === null) {
                $allArchived = false;
                continue;
            }

            $attachmentData = [
                'type' => 'dokument',
                'x-dio-metadaten' => $conversationData['x-dio-metadaten'],
                'subject' => $content['subject'],
                'body' => $content['body'],
                'Content-Type' => 'application/pdf; name="freescout.pdf"',
                'X-Dio-Zuordnungen' => $conversationData['X-Dio-Zuordnungen'],
                'X-Dio-Datum' => Carbon::parse($thread->created_at)->setTimezone($userTimezone)->format('Y-m-d\\TH:i:s'),
            ];

            if (!$this->apiClient->archiveConversation($attachmentData)) {
                \Helper::log('conversation_archive', 'Failed to archive attachment: ' . $content['subject']);
                $allArchived = false;
            }
        }

        return $allArchived;
    }

    private function buildConversationData($conversation, $thread, $crmUserId, $contracts, $divisions, $user = null): array
    {
        $user = $user ?? auth()->user();
        $userTimezone = $user->timezone;

        $xDioMetadaten = [];
        foreach (ArchiveContentHelper::metaPairs($conversation, $thread) as $key => $text) {
            $xDioMetadaten[] = ['Value' => $key, 'Text' => $text];
        }

        return [
            'type' => ($conversation->type == Conversation::TYPE_EMAIL) ? 'email' : 'telefon',
            'x-dio-metadaten' => $xDioMetadaten,
            'subject' => trim((string) ($conversation->subject ?? '')) !== ''
                ? $conversation->subject
                : '(Kein Betreff)',
            'body' => ArchiveContentHelper::cleanBody($thread->body),
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Dio-Datum' => Carbon::parse($thread->created_at)->setTimezone($userTimezone)->format('Y-m-d\\TH:i:s'),
            'X-Dio-Zuordnungen' => array_merge(
                [['Typ' => 'kunde', 'Id' => $crmUserId]],
                !is_null($contracts) ? array_map(fn($contract) => ['Typ' => 'vertrag', 'Id' => $contract['id']], $contracts) : [],
                !is_null($divisions) ? array_map(fn($division) => ['Typ' => 'sparte', 'Id' => $division['id']], $divisions) : []
            ),
        ];
    }
}
