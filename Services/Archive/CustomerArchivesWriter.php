<?php

namespace Modules\AmeiseModule\Services\Archive;

use App\Conversation;
use Carbon\Carbon;
use Modules\AmeiseModule\Services\CustomerArchivesApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * New archive strategy: writes archive entries through the Customer Archives API
 * using a JSON CreateArchiveRequestDto body. Attachments are written as separate
 * "document" entries (parity with the legacy behaviour).
 */
class CustomerArchivesWriter implements ArchiveWriterInterface
{
    private $client;
    private $tokenService;

    public function __construct(CustomerArchivesApiClient $client, TokenService $tokenService)
    {
        $this->client = $client;
        $this->tokenService = $tokenService;
    }

    public function archiveText($conversation, $thread, $crmUserId, $contracts, $divisions, $user = null): bool
    {
        $payload = $this->basePayload($conversation, $thread, $contracts, $divisions, $user);
        $payload['archiveType'] = ($conversation->type == Conversation::TYPE_EMAIL) ? 'email' : 'phone';
        $payload['subject'] = $this->subject($conversation->subject ?? '');
        $payload['text'] = mb_substr(ArchiveContentHelper::cleanBody($thread->body), 0, 65535);
        $payload['metadata'] = $this->metadata($conversation, $thread);

        return $this->client->createArchiveEntry($crmUserId, $payload);
    }

    public function archiveAttachments($conversation, $thread, $crmUserId, $contracts, $divisions, $user = null): bool
    {
        $allAttachments = $thread->attachments;
        if ($allAttachments->count() === 0) {
            return true;
        }

        $allArchived = true;
        foreach ($allAttachments as $attachment) {
            $content = ArchiveContentHelper::attachmentContent($attachment);
            if ($content === null) {
                $allArchived = false;
                continue;
            }

            $payload = $this->basePayload($conversation, $thread, $contracts, $divisions, $user);
            $payload['archiveType'] = 'document';
            $payload['subject'] = $this->subject($content['subject']);
            $payload['metadata'] = $this->metadata($conversation, $thread);
            $payload['files'] = [[
                'type' => 'main',
                'fileName' => mb_substr($content['subject'], 0, 255),
                'subject' => $this->subject($content['subject']),
                'file' => 'data:' . $content['mime'] . ';base64,' . base64_encode($content['body']),
            ]];

            if (!$this->client->createArchiveEntry($crmUserId, $payload)) {
                \Helper::log('conversation_archive', 'Failed to archive attachment: ' . $content['subject']);
                $allArchived = false;
            }
        }

        return $allArchived;
    }

    /**
     * Shared fields for both text and attachment entries.
     */
    private function basePayload($conversation, $thread, $contracts, $divisions, $user = null): array
    {
        $user = $user ?? auth()->user();

        $payload = [
            'isPublic' => filter_var(config('ameisemodule.ameise_archive_is_public'), FILTER_VALIDATE_BOOLEAN),
            'contracts' => !is_null($contracts) ? array_values(array_map(fn($contract) => $contract['id'], $contracts)) : [],
            'contractLines' => !is_null($divisions) ? array_values(array_map(fn($division) => $division['id'], $divisions)) : [],
            'date' => Carbon::parse($thread->created_at)->setTimezone($user->timezone)->format('c'),
        ];

        $ma = $this->tokenService->getMa();
        if (!empty($ma)) {
            $payload['author'] = ['type' => 'employee', 'id' => (string) $ma];
        }

        return $payload;
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function metadata($conversation, $thread): array
    {
        $metadata = [];
        foreach (ArchiveContentHelper::metaPairs($conversation, $thread) as $key => $text) {
            if ($text === null || $text === '') {
                continue;
            }
            $metadata[] = ['key' => mb_substr($key, 0, 64), 'value' => mb_substr((string) $text, 0, 128)];
        }

        return $metadata;
    }

    private function subject($subject): string
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            $subject = '(Kein Betreff)';
        }

        return mb_substr($subject, 0, 128);
    }
}
