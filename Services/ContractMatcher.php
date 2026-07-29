<?php

namespace Modules\AmeiseModule\Services;

/**
 * Bestimmt Vertrag und Sparte zu einer Konversation. Es wird nicht per Regex nach
 * Versicherungsscheinnummern geraten – stattdessen werden die Verträge des bereits
 * gefundenen Kunden geladen und deren Nummern im Nachrichtentext gesucht.
 */
class ContractMatcher
{
    /**
     * Kürzere Nummern führen im Volltext zu Zufallstreffern.
     */
    private const MIN_POLICY_NUMBER_LENGTH = 6;

    private $apiClient;

    public function __construct(CrmApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * @return array{contracts: array, divisions: array} Format wie im Modal:
     *                                                   [['id' => ..., 'text' => ...]]
     */
    public function match($crmUserId, $conversation): array
    {
        $empty = ['contracts' => [], 'divisions' => []];

        $contracts = $this->apiClient->getContracts($crmUserId);
        if (!is_array($contracts) || empty($contracts) || isset($contracts['error'])) {
            return $empty;
        }
        $contracts = array_values(array_filter($contracts, function ($contract) {
            return is_array($contract) && isset($contract['Id']);
        }));
        if (empty($contracts)) {
            return $empty;
        }

        $contract = $this->matchByPolicyNumber($contracts, $conversation);
        if (is_null($contract) && count($contracts) === 1) {
            // Hat der Kunde nur einen Vertrag, ist die Zuordnung ebenfalls eindeutig.
            $contract = $contracts[0];
        }
        if (is_null($contract)) {
            return $empty;
        }

        $divisionText = $this->divisionText($contract);

        return [
            'contracts' => [[
                'id'   => $contract['Id'],
                'text' => $this->contractText($contract, $divisionText),
            ]],
            // Die Sparte wird aus dem Vertrag abgeleitet, nicht geraten.
            'divisions' => (!empty($contract['Sparte']) && !is_null($divisionText))
                ? [['id' => $contract['Sparte'], 'text' => $divisionText]]
                : [],
        ];
    }

    private function matchByPolicyNumber(array $contracts, $conversation)
    {
        $haystack = $this->normalize(implode(' ', ConversationText::collect($conversation)));
        if ($haystack === '') {
            return null;
        }

        $matches = [];
        foreach ($contracts as $contract) {
            $number = $this->normalize((string) ($contract['Versicherungsscheinnummer'] ?? ''));
            if (mb_strlen($number) < self::MIN_POLICY_NUMBER_LENGTH) {
                continue;
            }
            if (mb_strpos($haystack, $number) !== false) {
                $matches[$contract['Id']] = $contract;
            }
        }

        // Nur eindeutige Treffer zuordnen.
        return count($matches) === 1 ? reset($matches) : null;
    }

    /**
     * Trennzeichen entfernen, damit "VS-Nr. 123 456-789" auf "123456789" passt.
     */
    private function normalize(string $text): string
    {
        $text = preg_replace('/\x{00A0}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\s\-\.\/_]+/u', '', $text) ?? $text;

        return mb_strtolower($text);
    }

    private function divisionText(array $contract)
    {
        if (empty($contract['Sparte'])) {
            return null;
        }

        $divisions = $this->apiClient->getContactEndPoints('sparten');
        if (!is_array($divisions) || isset($divisions['error'])) {
            return null;
        }

        $key = array_search($contract['Sparte'], array_column($divisions, 'Value'));

        return ($key !== false) ? ($divisions[$key]['Text'] ?? null) : null;
    }

    private function contractText(array $contract, $divisionText): string
    {
        // Gleiches Format wie die Auswahl im Modal.
        $parts = array_filter([
            $divisionText,
            $contract['Versicherungsscheinnummer'] ?? null,
            $contract['Risiko'] ?? null,
        ], function ($part) {
            return !is_null($part) && trim((string) $part) !== '';
        });

        return implode(' - ', $parts);
    }
}
