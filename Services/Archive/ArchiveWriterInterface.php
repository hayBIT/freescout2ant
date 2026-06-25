<?php

namespace Modules\AmeiseModule\Services\Archive;

/**
 * Strategy for writing a single thread (and its attachments) into the Ameise
 * archive. Split into two methods so the caller keeps full control over the
 * existing scan-only semantics and the resulting bookkeeping records.
 */
interface ArchiveWriterInterface
{
    /**
     * Archive the text/body of a thread.
     */
    public function archiveText($conversation, $thread, $crmUserId, $contracts, $divisions, $user = null): bool;

    /**
     * Archive the attachments of a thread. Returns true when all attachments
     * were archived (or there are none).
     */
    public function archiveAttachments($conversation, $thread, $crmUserId, $contracts, $divisions, $user = null): bool;
}
