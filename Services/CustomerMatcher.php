<?php

namespace Modules\AmeiseModule\Services;

/**
 * Ermittelt Ameise-Kunden zu einer Konversation – über die Kunden-E-Mail und über
 * Kundennummern im Betreff bzw. Nachrichtentext. Wird sowohl für die Vorschläge im
 * Modal als auch für die automatische Zuordnung genutzt, damit beide Wege identisch
 * suchen.
 */
class CustomerMatcher
{
    private $apiClient;

    public function __construct(CrmApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * @param bool $includeCustomerNumbers Auch nach Kundennummern im Text suchen.
     *
     * @return array{candidates: array, source: string|null, unique: bool, redirect: string|null}
     */
    public function match($conversation, bool $includeCustomerNumbers = true): array
    {
        $emailCandidates = [];
        $numberCandidates = [];

        if (!empty($conversation->customer_email)) {
            $response = $this->apiClient->fetchUserByEmail($conversation->customer_email);
            if ($this->redirectUrl($response)) {
                return $this->result([], null, $this->redirectUrl($response));
            }
            $emailCandidates = $this->onlyCustomers($response);
        }

        if ($includeCustomerNumbers) {
            foreach ($this->extractCustomerNumbersFromConversation($conversation) as $customerNumber) {
                $response = $this->apiClient->fetchUserByIdOrName($customerNumber);
                if ($this->redirectUrl($response)) {
                    return $this->result([], null, $this->redirectUrl($response));
                }
                $numberCandidates = array_merge($numberCandidates, $this->onlyCustomers($response));
            }
        }

        $candidates = $this->uniqueCrmUsersById(array_merge($emailCandidates, $numberCandidates));

        $source = null;
        if (!empty($emailCandidates) && !empty($numberCandidates)) {
            $source = 'both';
        } elseif (!empty($emailCandidates)) {
            $source = 'email';
        } elseif (!empty($numberCandidates)) {
            $source = 'customer_number';
        }

        return $this->result($candidates, $source, null);
    }

    /**
     * Kundennummern aus Betreff und allen Nachrichten einer Konversation.
     *
     * @return string[]
     */
    public function extractCustomerNumbersFromConversation($conversation): array
    {
        $customerNumbers = [];

        foreach (ConversationText::collect($conversation) as $text) {
            $numbers = $this->extractCustomerNumbers($text);
            if (!empty($numbers)) {
                $customerNumbers = array_merge($customerNumbers, $numbers);
            }
        }

        return array_values(array_unique($customerNumbers));
    }

    /**
     * @return string[]
     */
    public function extractCustomerNumbers($text): array
    {
        $decodedText = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $decodedUrlText = rawurldecode($decodedText);
        $plainText = strip_tags($decodedText);

        // Search both the rendered text and the original HTML so customer numbers
        // in signatures, hidden spans, or link attributes (for example kid=...)
        // are detected.
        $searchableText = implode(' ', array_unique([
            $text,
            $decodedText,
            $decodedUrlText,
            $plainText,
        ]));

        if (preg_match_all('/(?<!\d)5\d{9}(?!\d)/', $searchableText, $matches) > 0) {
            return array_values(array_unique($matches[0]));
        }

        return [];
    }

    public function uniqueCrmUsersById(array $users): array
    {
        $uniqueUsers = [];
        foreach ($users as $user) {
            if (isset($user['Id'])) {
                $uniqueUsers[$user['Id']] = $user;
            }
        }

        return array_values($uniqueUsers);
    }

    /**
     * Fehler-Antworten der API (abgelaufener Token o. Ä.) sehen wie
     * ['error' => 'redirect', 'url' => ...] aus und sind keine Kundentreffer.
     */
    private function redirectUrl($response)
    {
        if (is_array($response) && isset($response['error'], $response['url'])) {
            return $response['url'];
        }

        return null;
    }

    private function onlyCustomers($response): array
    {
        if (!is_array($response)) {
            return [];
        }

        return array_values(array_filter($response, function ($item) {
            return is_array($item) && isset($item['Id']);
        }));
    }

    private function result(array $candidates, $source, $redirect): array
    {
        return [
            'candidates' => $candidates,
            'source'     => $source,
            'unique'     => count($candidates) === 1,
            'redirect'   => $redirect,
        ];
    }
}
