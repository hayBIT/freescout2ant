<?php

namespace Modules\AmeiseModule\Services\Archive;

/**
 * Shared helpers for building archive payloads, reused by both the
 * MitarbeiterWebservice and the Customer Archives write strategies.
 */
class ArchiveContentHelper
{
    /**
     * Build the ordered An/Von/CC/BCC metadata pairs for a thread.
     *
     * @return array<string, string|null> keyed by An/Von/CC/BCC
     */
    public static function metaPairs($conversation, $thread): array
    {
        $metaData = [
            'An' => !empty($thread->to) ? json_decode($thread->to) : null,
            'Von' => !empty($thread->from) ? $thread->from : ($conversation->mailbox_id ? $conversation->mailbox->email : null),
            'CC' => !empty($thread->cc) ? json_decode($thread->cc) : null,
            'BCC' => !empty($thread->bcc) ? json_decode($thread->bcc) : null,
        ];

        $pairs = [];
        foreach ($metaData as $key => $value) {
            $pairs[$key] = is_array($value) ? implode(', ', $value) : $value;
        }

        return $pairs;
    }

    /**
     * Convert a thread HTML body to the plain-text representation used for archiving.
     */
    public static function cleanBody($body): string
    {
        $body = $body ?? '';
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

        return $body;
    }

    /**
     * Read an attachment from disk, converting images to PDF when possible.
     *
     * @return array{body: string, subject: string, mime: string}|null null when the file is missing
     */
    public static function attachmentContent($attachment): ?array
    {
        $path = storage_path("app/attachment/{$attachment['file_dir']}{$attachment['file_name']}");
        if (!file_exists($path)) {
            \Helper::log('conversation_archive', 'Attachment file not found: ' . $path);
            return null;
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
                $mimeType = 'application/pdf';
            } catch (\Exception $e) {
                \Helper::log('conversation_archive', 'Failed to convert image to PDF: ' . $e->getMessage());
            }
        }

        return ['body' => $body, 'subject' => $subject, 'mime' => $mimeType];
    }
}
