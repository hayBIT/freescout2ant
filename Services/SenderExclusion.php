<?php

namespace Modules\AmeiseModule\Services;

/**
 * Absenderadressen, die in den Ameise-Einstellungen von der Archivierung
 * ausgeschlossen wurden.
 *
 * Die Liste wird im Einstellungsmenü gepflegt (eine Adresse pro Zeile) und in
 * der FreeScout-Options-Tabelle gespeichert. Als Vorbelegung dient die
 * Umgebungsvariable AMEISE_EXCLUDED_SENDERS.
 */
class SenderExclusion
{
    const OPTION_NAME = 'ameise_excluded_senders';

    /**
     * Gecachte, normalisierte Muster.
     *
     * @var array|null
     */
    private $patterns = null;

    /**
     * Rohwert der Einstellung, wie er im Einstellungsformular angezeigt wird.
     *
     * @return string
     */
    public static function getRawSetting()
    {
        $raw = null;

        try {
            if (class_exists('\App\Option')) {
                $raw = \App\Option::get(self::OPTION_NAME, config('ameisemodule.ameise_excluded_senders'));
            }
        } catch (\Exception $e) {
            // Ohne Datenbank (z. B. während der Installation) greift die Config.
            $raw = null;
        }

        if ($raw === null || $raw === false) {
            $raw = config('ameisemodule.ameise_excluded_senders');
        }

        if (is_array($raw)) {
            $raw = implode("\n", $raw);
        }

        return (string) $raw;
    }

    /**
     * Normalisierte Muster der ausgeschlossenen Absender.
     *
     * @return array
     */
    public function getPatterns()
    {
        if (is_array($this->patterns)) {
            return $this->patterns;
        }

        $patterns = preg_split('/[\r\n,;]+/', self::getRawSetting());
        $patterns = array_map(function ($pattern) {
            return mb_strtolower(trim((string) $pattern));
        }, is_array($patterns) ? $patterns : []);

        $this->patterns = array_values(array_unique(array_filter($patterns, function ($pattern) {
            return $pattern !== '';
        })));

        return $this->patterns;
    }

    /**
     * Ist überhaupt ein Absender ausgeschlossen?
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->getPatterns());
    }

    /**
     * Liefert die ausgeschlossene Adresse der Konversation bzw. des Threads
     * oder null, wenn nichts ausgeschlossen ist.
     *
     * @param \App\Conversation $conversation
     * @param \App\Thread|null  $thread
     *
     * @return string|null
     */
    public function getExcludedSender($conversation, $thread = null)
    {
        $patterns = $this->getPatterns();
        if (empty($patterns)) {
            return null;
        }

        foreach ($this->collectAddresses($conversation, $thread) as $address) {
            foreach ($patterns as $pattern) {
                if ($this->matches($address, $pattern)) {
                    return $address;
                }
            }
        }

        return null;
    }

    /**
     * Mitteilungstext für eine übersprungene E-Mail.
     *
     * @param string $sender
     *
     * @return string
     */
    public function getMessage($sender)
    {
        return __(
            'Die E-Mail von :sender wurde nicht in der Ameise archiviert, da diese Absenderadresse in den Ameise-Einstellungen von der Archivierung ausgeschlossen ist.',
            ['sender' => $sender]
        );
    }

    /**
     * Benachrichtigt den Benutzer über eine übersprungene E-Mail.
     *
     * Im Web-Request erscheint eine Meldung im Oberflächen-Overlay, im Cron
     * bzw. Queue-Worker bleibt nur der (optionale) Log-Eintrag.
     *
     * @param \App\Conversation $conversation
     * @param string            $sender
     */
    public function notify($conversation, $sender)
    {
        $message = $this->getMessage($sender);
        $context = ' (conversation_id: ' . ($conversation->id ?? '') . ')';

        if (app()->runningInConsole()) {
            config('ameisemodule.ameise_log_status') && \Helper::log('Ameise Cron Log', $message . $context);

            return;
        }

        \Helper::log('conversation_archive', $message . $context);

        try {
            \Session::flash('flash_warning_floating', htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        } catch (\Exception $e) {
            // Ohne Session genügt der Log-Eintrag.
        }
    }

    /**
     * Alle Adressen, die als Absender der Konversation in Frage kommen.
     *
     * Neben dem Absender des Threads zählt auch der Kunde der Konversation:
     * sonst würden eigene Antworten an eine ausgeschlossene Adresse trotzdem
     * archiviert.
     *
     * @return array
     */
    private function collectAddresses($conversation, $thread = null)
    {
        $addresses = [];

        if ($thread && !empty($thread->from)) {
            $addresses[] = $thread->from;
        }

        if (!empty($conversation->customer_email)) {
            $addresses[] = $conversation->customer_email;
        }

        $result = [];
        foreach ($addresses as $address) {
            $address = $this->normalizeAddress($address);
            if ($address !== '' && !in_array($address, $result, true)) {
                $result[] = $address;
            }
        }

        return $result;
    }

    /**
     * "Name <mail@example.com>" -> "mail@example.com".
     *
     * @return string
     */
    private function normalizeAddress($address)
    {
        $address = trim((string) $address);
        if ($address === '') {
            return '';
        }

        if (preg_match('/<([^<>]+)>/', $address, $matches)) {
            $address = trim($matches[1]);
        }

        return mb_strtolower($address);
    }

    /**
     * Vergleicht eine Adresse mit einem Muster aus den Einstellungen.
     *
     * Unterstützt werden vollständige Adressen, Platzhalter (* und ?) sowie
     * reine Domains ("@example.com" bzw. "example.com").
     *
     * @return bool
     */
    private function matches($address, $pattern)
    {
        if ($address === '' || $pattern === '') {
            return false;
        }

        if ($address === $pattern) {
            return true;
        }

        if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
            $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/u';

            return (bool) preg_match($regex, $address);
        }

        // Eintrag ohne lokalen Teil schließt die komplette Domain aus.
        $atPosition = strpos($pattern, '@');
        if ($atPosition === false || $atPosition === 0) {
            $domain = '@' . ltrim($pattern, '@');

            return substr($address, -strlen($domain)) === $domain;
        }

        return false;
    }
}
