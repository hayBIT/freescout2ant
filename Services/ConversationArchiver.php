<?php

namespace Modules\AmeiseModule\Services;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

class ConversationArchiver
{
    private const MAX_SUBJECT_LENGTH = 128;

    private $apiClient;
    private $customerMatcher;
    private $contractMatcher;

    public function __construct(CrmApiClient $apiClient, ?CustomerMatcher $customerMatcher = null, ?ContractMatcher $contractMatcher = null)
    {
        $this->apiClient = $apiClient;
        $this->customerMatcher = $customerMatcher ?? new CustomerMatcher($apiClient);
        $this->contractMatcher = $contractMatcher ?? new ContractMatcher($apiClient);
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

    public function archiveConversationWithAttachments($thread, $conversation_data, $user = null)
    {
        $allAttachments = $thread->attachments;
        $user = $user ?? auth()->user();
        $userTimezone = $user->timezone;
        $allArchived = true;

        if ($allAttachments->count() > 0) {
            foreach ($allAttachments as $attachment) {
                $path = storage_path("app/attachment/{$attachment['file_dir']}{$attachment['file_name']}");
                if (!file_exists($path)) {
                    \Helper::log('conversation_archive', 'Attachment file not found: ' . $path);
                    $allArchived = false;
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
                if (!$this->apiClient->archiveConversation($attachmentData)) {
                    \Helper::log('conversation_archive', 'Failed to archive attachment: ' . $subject);
                    $allArchived = false;
                }
            }
        }

        return $allArchived;
    }

    public function isScanOnly($conversation)
    {
        return stripos($conversation->subject ?? '', '#scanonly') !== false;
    }

    public function archiveConversationData($conversation, $thread = null, $user = null)
    {
        $thread =  $thread ?? $conversation->getLastThread();
        $user = $user ?? auth()->user();
        if (!$thread || !$this->shouldArchiveThread($conversation, $thread)) {
            return;
        }

        $crmArchives = CrmArchive::where('conversation_id', $conversation->id)->get();
        if (count($crmArchives) > 0) {
            foreach ($crmArchives as $crmArchive) {
                $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crmArchive->id)->where('thread_id', $thread->id)->first();
                if (!$isArchiveThread) {
                    $contracts = !empty($crmArchive->contracts) ? json_decode($crmArchive->contracts, true) : [];
                    $divisions = !empty($crmArchive->divisions) ? json_decode($crmArchive->divisions, true) : [];
                    $this->archiveThread($conversation, $crmArchive, $contracts, $divisions, $thread, $user);
                }
            }

            return;
        }

        // Noch keine Zuordnung vorhanden: automatisch versuchen, aber nur den
        // auslösenden Thread archivieren (bisheriges Verhalten).
        $this->autoAssignConversation($conversation, $user, $thread);
    }

    /**
     * Ordnet eine noch nicht zugeordnete Konversation automatisch einem Ameise-Kunden
     * zu und archiviert sie – ausschließlich bei eindeutigem Treffer.
     *
     * @param mixed $thread Nur diesen Thread archivieren; null = alle Threads.
     *
     * @return bool
     */
    public function autoAssignConversation($conversation, $user = null, $thread = null)
    {
        $user = $user ?? auth()->user();
        if (!$user) {
            return false;
        }

        if (CrmArchive::where('conversation_id', $conversation->id)->exists()) {
            return false;
        }

        $match = $this->resolveCustomer($conversation);
        if (!$match['unique']) {
            return false;
        }

        $candidate = $match['candidates'][0];
        $crmUserId = $candidate['Id'];
        $assignment = $this->resolveContracts($crmUserId, $conversation);

        $crmArchive = new CrmArchive();
        $crmArchive->conversation_id = $conversation->id;
        $crmArchive->crm_user_id = $crmUserId;
        $crmArchive->archived_by = $user->id;
        $crmArchive->crm_user = json_encode(['id' => $crmUserId, 'text' => $candidate['Text'] ?? '']);
        $crmArchive->contracts = !empty($assignment['contracts']) ? json_encode($assignment['contracts']) : null;
        $crmArchive->divisions = !empty($assignment['divisions']) ? json_encode($assignment['divisions']) : null;
        $crmArchive->auto_assigned = 1;
        $crmArchive->match_source = $match['source'];
        $crmArchive->save();

        if ($thread) {
            $contracts = $assignment['contracts'];
            $divisions = $assignment['divisions'];
            $this->archiveThread($conversation, $crmArchive, $contracts, $divisions, $thread, $user);
        } else {
            $this->archiveThreadsForArchive($conversation, $crmArchive, $user);
        }

        // Ist nichts im CRM gelandet (z. B. API-Fehler), die Zuordnung wieder entfernen.
        // Sonst gilt die Konversation als bearbeitet und würde nie erneut versucht.
        if (!CrmArchiveThread::where('crm_archive_id', $crmArchive->id)->exists()) {
            $crmArchive->delete();

            return false;
        }

        // Automatische CRM-Schreibvorgänge protokollieren, solange die Automatik aktiv ist –
        // das ist der Prüfpfad für Fehlzuordnungen.
        if ($this->autoAssignEnabled() || config('ameisemodule.ameise_log_status')) {
            \Helper::log(
                'ameise_auto_assign',
                'Konversation ' . $conversation->id . ' automatisch Kunde ' . $crmUserId
                . ' zugeordnet (Quelle: ' . $match['source'] . ', Verträge: ' . count($assignment['contracts']) . ').'
            );
        }

        return true;
    }

    /**
     * Kandidatensuche. Ist die Automatik deaktiviert, wird wie bisher ausschließlich
     * über die Kunden-E-Mail zugeordnet.
     */
    public function resolveCustomer($conversation): array
    {
        return $this->customerMatcher->match($conversation, $this->autoAssignEnabled());
    }

    private function resolveContracts($crmUserId, $conversation): array
    {
        if (!$this->autoAssignEnabled() || !config('ameisemodule.ameise_auto_assign_contracts')) {
            return ['contracts' => [], 'divisions' => []];
        }

        return $this->contractMatcher->match($crmUserId, $conversation);
    }

    private function autoAssignEnabled(): bool
    {
        return (bool) config('ameisemodule.ameise_auto_assign');
    }

    /**
     * Archiviert alle noch nicht archivierten Threads einer Konversation für eine
     * bestehende Zuordnung.
     *
     * @return bool true, wenn alle in Frage kommenden Threads archiviert wurden
     */
    public function archiveThreadsForArchive($conversation, $crmArchive, $user = null)
    {
        $user = $user ?? auth()->user();
        $contracts = !empty($crmArchive->contracts) ? json_decode($crmArchive->contracts, true) : [];
        $divisions = !empty($crmArchive->divisions) ? json_decode($crmArchive->divisions, true) : [];
        $allArchived = true;

        foreach ($conversation->threads as $thread) {
            if (CrmArchiveThread::where('crm_archive_id', $crmArchive->id)->where('thread_id', $thread->id)->exists()) {
                continue;
            }
            if (!$this->shouldArchiveThread($conversation, $thread)) {
                continue;
            }
            if (!$this->archiveThread($conversation, $crmArchive, $contracts, $divisions, $thread, $user)) {
                $allArchived = false;
            }
        }

        return $allArchived;
    }

    /**
     * Überträgt einen einzelnen Thread ins CRM und vermerkt ihn bei Erfolg.
     */
    private function archiveThread($conversation, $crmArchive, $contracts, $divisions, $thread, $user)
    {
        $conversation_data = $this->createConversationData($conversation, $crmArchive->crm_user_id, $contracts, $divisions, $thread, $user);
        $scanOnly = $this->isScanOnly($conversation);
        $archived = $scanOnly ? true : $this->apiClient->archiveConversation($conversation_data);
        $attachmentsArchived = $archived ? $this->archiveConversationWithAttachments($thread, $conversation_data, $user) : false;

        if ($archived && (!$scanOnly || $attachmentsArchived)) {
            CrmArchiveThread::create([
                'crm_archive_id'  => $crmArchive->id,
                'thread_id'       => $thread->id,
                'conversation_id' => $conversation->id,
            ]);

            return true;
        }

        return false;
    }
}
