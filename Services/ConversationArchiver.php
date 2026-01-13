<?php

namespace Modules\AmeiseModule\Services;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

class ConversationArchiver
{
    private $apiClient;

    public function __construct(CrmApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
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

        $body = $thread->body ?? '';
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
        $body = str_replace(['<li>', '</li>'], ["\n- ", ''], $body);
        $body = preg_replace('/<br\s*\/?\s*>/i', "\n", $body);
        $body = preg_replace('/<\/p>\s*<p>/i', "\n\n", $body);
        $body = preg_replace('/<\/div>\s*<div>/i', "\n\n", $body);
        $body = preg_replace('/<\/(p|div)>/i', "\n", $body);
        $body = strip_tags($body);
        $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
        
        return [
            'type' =>  ($conversation->type == Conversation::TYPE_EMAIL) ? 'email' : 'telefon',
            'x-dio-metadaten' => $x_dio_metadaten,
            'subject' => $conversation->subject,
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
        if ($allAttachments->count() > 0) {
            foreach ($allAttachments as $attachment) {
                $path = storage_path("app/attachment/{$attachment['file_dir']}{$attachment['file_name']}");
                if (!file_exists($path)) {
                    \Helper::log('conversation_archive', 'Attachment file not found: ' . $path);
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
                $this->apiClient->archiveConversation($attachmentData);
            }
        }
    }

    public function archiveConversationData($conversation, $thread = null, $user = null)
    {
        $thread =  $thread ?? $conversation->getLastThread();
        $user = $user ?? auth()->user();
        if($thread->type != Thread::TYPE_NOTE){
            $crmArchives = CrmArchive::where('conversation_id', $conversation->id)->get();
            if (count($crmArchives) > 0) {
                foreach ($crmArchives as $crmArchive) {
                    $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crmArchive->id)->where('thread_id',$thread->id)->first();
                    if(!$isArchiveThread){
                        $contracts = !empty($crmArchive->contracts) ? json_decode($crmArchive->contracts, true) : [];
                        $divisions = !empty($crmArchive->divisions) ? json_decode($crmArchive->divisions, true) : [];
                        $conversation_data = $this->createConversationData($conversation, $crmArchive->crm_user_id, $contracts, $divisions, $thread, $user);
                        if($this->apiClient->archiveConversation($conversation_data)) {
                            $this->archiveConversationWithAttachments($thread, $conversation_data, $user);
                            CrmArchiveThread::create(['crm_archive_id' => $crmArchive->id,'thread_id' => $thread->id,'conversation_id'=> $conversation->id ]);
                        }
                    }
                }
            } else {
                $response = $this->apiClient->fetchUserByEmail($conversation->customer_email);
                if (count($response) == 1) {
                    $crm_user_id = $response[0]['Id'];
                    $conversation_data  = $this->createConversationData($conversation, $crm_user_id, [], [], $thread, $user);
                    if($this->apiClient->archiveConversation($conversation_data)) {
                        $this->archiveConversationWithAttachments($thread, $conversation_data, $user);
                        $crm_archive = CrmArchive::firstOrNew(['conversation_id' => $conversation->id, 'crm_user_id' => $crm_user_id,'archived_by' => $user->id]);
                        $crm_archive->crm_user = json_encode(['id' => $crm_user_id, 'text' => $response[0]['Text']]);
                        $crm_archive->contracts = null;
                        $crm_archive->divisions = null;
                        $crm_archive->save();
                        CrmArchiveThread::create(['crm_archive_id' => $crm_archive->id,'thread_id' => $thread->id,'conversation_id'=> $conversation->id ]);
                    }
                }
            }
        }

    }
}
