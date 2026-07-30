<?php

namespace Modules\AmeiseModule\Services;

use App\Thread;

/**
 * Sammelt die durchsuchbaren Texte einer Konversation (Betreff + Thread-Inhalte).
 * Wird von CustomerMatcher und ContractMatcher genutzt, damit beide dieselbe
 * Textbasis für ihre Treffersuche verwenden.
 */
class ConversationText
{
    /**
     * @return string[]
     */
    public static function collect($conversation): array
    {
        $texts = [];

        if (!empty($conversation->subject)) {
            $texts[] = (string) $conversation->subject;
        }

        foreach ($conversation->threads as $thread) {
            // Line-Items enthalten keinen Kundeninhalt, nur Statuswechsel.
            if ($thread->type == Thread::TYPE_LINEITEM) {
                continue;
            }
            if (!empty($thread->body)) {
                $texts[] = (string) $thread->body;
            }
        }

        return $texts;
    }
}
