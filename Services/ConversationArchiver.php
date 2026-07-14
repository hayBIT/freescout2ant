<?php

namespace Modules\AmeiseModule\Services;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveAttempt;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

class ConversationArchiver
{
    private const MAX_SUBJECT_LENGTH = 128;

    private $apiClient;

    public function __construct(CrmApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
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

    public function createConversationData($conversation, $crm_user_id, $contracts, $divisions, $thread, $user = null)
    {
        $user = $user ?? auth()->user();
        $userTimezone = $user->timezone;
        $x_dio_metadaten = [];
        $metaData = [
            'An' => !empty($thread->to) ? json_decode($thread->to) : null,
            'Von' => !empty($thread->from) ? $thread->from : ($conversation->mailbox_id ? $conversation->mailbox->email : null),
            'CC' =>   !empty($thread->cc) ? json_decode($thread->cc) : null,
            'BCC' =>    !empty($thread->bcc) ? json_decode($thread->bcc) : null,
        ];
        foreach ($metaData as $key => $value) {
            $text = is_array($value) ? implode(', ', $value) : $value;
            $x_dio_metadaten[] = ['Value' => $key, 'Text' => $text];
        }

        $subject = trim((string) ($conversation->subject ?? '')) !== ''
            ? trim((string) $conversation->subject)
            : '(Kein Betreff)';
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            $x_dio_metadaten[] = ['Value' => 'Vollständiger Betreff', 'Text' => $subject];
        }

        $body = $thread->body ?? '';
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
        $body = str_replace(['<li>', '</li>'], ["\n- ", ''], $body);
        $body = preg_replace('/<br\s*\/?\s*>/i', "\n", $body);
        $body = preg_replace('/<\/p>\s*<p>/i', "\n\n", $body);
        $body = preg_replace('/<\/div>\s*<div>/i', "\n\n", $body);
        $body = preg_replace('/<\/(p|div)>/i', "\n", $body);
        $body = strip_tags($body);
        $body = preg_replace('/\x{00A0}/u', ' ', $body);
        $body = preg_replace("/\r\n|\r/", "\n", $body);
        $body = preg_replace("/\n{3,}/", "\n\n", $body);
        $body = str_replace("\n", "\r\n", $body);

        return [
            'type' =>  ($conversation->type == Conversation::TYPE_EMAIL) ? 'email' : 'telefon',
            'x-dio-metadaten' => $x_dio_metadaten,
            'subject' => $subject,
            'body' => $body,
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Dio-Datum' => Carbon::parse($thread->created_at)->setTimezone($userTimezone)->format('Y-m-d\TH:i:s'),
            'X-Dio-Zuordnungen' => array_merge(
                [['Typ' => 'kunde', 'Id' => $crm_user_id]],
                !is_null($contracts) ? array_map(fn($contract) => ['Typ' => 'vertrag', 'Id' => $contract['id']], $contracts) : [],
                !is_null($divisions) ? array_map(fn($division) => ['Typ' => 'sparte', 'Id' => $division['id']], $divisions) : []
            ),
        ];
    }

    /**
     * @return array{archived_ok: bool, total: int, failed: int, last_failure: ?array}
     */
    public function archiveConversationWithAttachments($thread, $conversation_data, $user = null)
    {
        $allAttachments = $thread->attachments;
        $user = $user ?? auth()->user();
        $userTimezone = $user->timezone;
        $total = $allAttachments->count();
        $failed = 0;
        $lastFailure = null;

        if ($total > 0) {
            foreach ($allAttachments as $attachment) {
                $path = storage_path("app/attachment/{$attachment['file_dir']}{$attachment['file_name']}");
                if (!file_exists($path)) {
                    \Helper::log('conversation_archive', 'Attachment file not found: ' . $path);
                    $failed++;
                    $lastFailure = ['reason' => 'attachment_file_missing', 'detail' => $attachment['file_name'] ?? null];
                    continue;
                }
                $body = file_get_contents($path);
                $mimeType = mime_content_type($path);
                $subject = $attachment['file_name'];
                if (strpos($mimeType, 'image/') === 0 && extension_loaded('imagick')) {
                    try {
                        $img = new \Imagick($path);
                        $img->setImageFormat('pdf');
                        $body = $img->getImagesBlob();
                        $subject = pathinfo($subject, PATHINFO_FILENAME) . '.pdf';
                    } catch (\Exception $e) {
                        \Helper::log('conversation_archive', 'Failed to convert image to PDF: ' . $e->getMessage());
                    }
                }
                $attachmentData = [
                    'type' => 'dokument',
                    'x-dio-metadaten' => $conversation_data['x-dio-metadaten'],
                    'subject' => $subject,
                    'body' => $body,
                    'Content-Type' => 'application/pdf; name="freescout.pdf"',
                    'X-Dio-Zuordnungen' => $conversation_data['X-Dio-Zuordnungen'],
                    'X-Dio-Datum' => Carbon::parse($thread->created_at)->setTimezone($userTimezone)->format('Y-m-d\\TH:i:s')
                ];
                $result = $this->apiClient->archiveConversationDetailed($attachmentData);
                if (!$result['ok']) {
                    \Helper::log('conversation_archive', 'Failed to archive attachment: ' . $subject);
                    $failed++;
                    $lastFailure = [
                        'reason' => 'attachment_api_failed',
                        'detail' => $subject,
                        'http_status' => $result['http_status'],
                        'body' => $result['body'],
                        'exception' => $result['exception'],
                    ];
                }
            }
        }

        return [
            'archived_ok' => $failed === 0,
            'total' => $total,
            'failed' => $failed,
            'last_failure' => $lastFailure,
        ];
    }

    public function isScanOnly($conversation)
    {
        return stripos($conversation->subject ?? '', '#scanonly') !== false;
    }

    public function archiveConversationData($conversation, $thread = null, $user = null)
    {
        $thread =  $thread ?? $conversation->getLastThread();
        $user = $user ?? auth()->user();
        if (!$this->shouldArchiveThread($conversation, $thread)) {
            return;
        }

        $crmArchives = CrmArchive::where('conversation_id', $conversation->id)->get();
        if (count($crmArchives) > 0) {
            foreach ($crmArchives as $crmArchive) {
                $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crmArchive->id)->where('thread_id', $thread->id)->first();
                if ($isArchiveThread) {
                    continue;
                }
                $contracts = !empty($crmArchive->contracts) ? json_decode($crmArchive->contracts, true) : [];
                $divisions = !empty($crmArchive->divisions) ? json_decode($crmArchive->divisions, true) : [];
                $conversation_data = $this->createConversationData($conversation, $crmArchive->crm_user_id, $contracts, $divisions, $thread, $user);
                $this->performArchive($conversation, $thread, $user, $crmArchive, $conversation_data);
            }
            return;
        }

        $response = $this->apiClient->fetchUserByEmail($conversation->customer_email);
        if (count($response) === 1) {
            $crm_user_id = $response[0]['Id'];
            $conversation_data = $this->createConversationData($conversation, $crm_user_id, [], [], $thread, $user);
            $crm_archive = CrmArchive::firstOrNew([
                'conversation_id' => $conversation->id,
                'crm_user_id' => $crm_user_id,
                'archived_by' => $user->id,
            ]);
            $crm_archive->crm_user = json_encode(['id' => $crm_user_id, 'text' => $response[0]['Text']]);
            $crm_archive->contracts = null;
            $crm_archive->divisions = null;
            $crm_archive->save();
            $this->performArchive($conversation, $thread, $user, $crm_archive, $conversation_data);
            return;
        }

        $status = count($response) === 0
            ? CrmArchiveAttempt::STATUS_FAILED_NO_CUSTOMER
            : CrmArchiveAttempt::STATUS_FAILED_AMBIGUOUS_CUSTOMER;
        CrmArchiveAttempt::record([
            'conversation_id' => $conversation->id,
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'status' => $status,
            'reason' => $status === CrmArchiveAttempt::STATUS_FAILED_NO_CUSTOMER
                ? 'Kein Ameise-Kunde fuer ' . $conversation->customer_email . ' gefunden.'
                : 'Mehrere Ameise-Kunden (' . count($response) . ') fuer ' . $conversation->customer_email . ' gefunden.',
        ]);
    }

    private function performArchive($conversation, $thread, $user, $crmArchive, array $conversation_data)
    {
        $scanOnly = $this->isScanOnly($conversation);
        if ($scanOnly) {
            $archiveResult = ['ok' => true, 'http_status' => null, 'body' => null, 'exception' => null, 'token_error' => false];
        } else {
            $archiveResult = $this->apiClient->archiveConversationDetailed($conversation_data);
        }

        if (!$archiveResult['ok']) {
            $status = $archiveResult['token_error']
                ? CrmArchiveAttempt::STATUS_FAILED_TOKEN
                : CrmArchiveAttempt::STATUS_FAILED_API;
            CrmArchiveAttempt::record([
                'conversation_id' => $conversation->id,
                'thread_id' => $thread->id,
                'user_id' => $user->id,
                'status' => $status,
                'reason' => $archiveResult['exception']
                    ?: ('HTTP ' . ($archiveResult['http_status'] ?? 'n/a')),
                'http_status' => $archiveResult['http_status'],
                'response_body' => $archiveResult['body'],
            ]);
            return;
        }

        $attachmentResult = $this->archiveConversationWithAttachments($thread, $conversation_data, $user);

        if ($scanOnly && !$attachmentResult['archived_ok']) {
            $lastFailure = $attachmentResult['last_failure'] ?? [];
            CrmArchiveAttempt::record([
                'conversation_id' => $conversation->id,
                'thread_id' => $thread->id,
                'user_id' => $user->id,
                'status' => CrmArchiveAttempt::STATUS_FAILED_ATTACHMENT,
                'reason' => '#scanonly: ' . ($lastFailure['reason'] ?? 'attachment failure') . ' (' . ($lastFailure['detail'] ?? '') . ')',
                'http_status' => $lastFailure['http_status'] ?? null,
                'response_body' => $lastFailure['body'] ?? null,
                'attachments_total' => $attachmentResult['total'],
                'attachments_failed' => $attachmentResult['failed'],
            ]);
            return;
        }

        CrmArchiveThread::create([
            'crm_archive_id' => $crmArchive->id,
            'thread_id' => $thread->id,
            'conversation_id' => $conversation->id,
        ]);

        $status = $attachmentResult['archived_ok']
            ? CrmArchiveAttempt::STATUS_SUCCESS
            : CrmArchiveAttempt::STATUS_FAILED_ATTACHMENT;
        $lastFailure = $attachmentResult['last_failure'] ?? [];
        CrmArchiveAttempt::record([
            'conversation_id' => $conversation->id,
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'status' => $status,
            'reason' => $status === CrmArchiveAttempt::STATUS_SUCCESS
                ? null
                : 'Nachricht archiviert, Anhang-Fehler: ' . ($lastFailure['reason'] ?? '') . ' (' . ($lastFailure['detail'] ?? '') . ')',
            'http_status' => $status === CrmArchiveAttempt::STATUS_SUCCESS ? ($archiveResult['http_status'] ?? null) : ($lastFailure['http_status'] ?? null),
            'response_body' => $status === CrmArchiveAttempt::STATUS_SUCCESS ? null : ($lastFailure['body'] ?? null),
            'attachments_total' => $attachmentResult['total'],
            'attachments_failed' => $attachmentResult['failed'],
        ]);
    }
}
