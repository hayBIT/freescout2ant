<?php

namespace Modules\AmeiseModule\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Modules\AmeiseModule\Services\Concerns\SanitizesLogs;

/**
 * Client für die neue Archive-API (customer-archives).
 *
 * Anders als die Mitarbeiter-API kann diese API bestehende Archiveinträge ändern
 * und löschen. Sie kennt dafür keine Kundensuche — Kunden, Verträge und Sparten
 * kommen weiterhin über den CrmApiClient.
 *
 * Wichtig für updateArchiveEntry(): im UpdateArchiveRequestDto sind isPublic und
 * isDeleted Pflichtfelder, tags/metadata/contracts/contractLines haben default [].
 * Ein PATCH, der diese Felder weglässt, leert sie. Aufrufer müssen den Eintrag
 * deshalb erst per getArchiveEntry() laden und den vollständigen, gemergten
 * Zustand senden (Read-Modify-Write).
 */
class ArchiveApiClient
{
    use SanitizesLogs;

    /**
     * Der Host der Testumgebung ist aus dem Muster der übrigen inte-Hosts
     * abgeleitet; die OpenAPI-Datei nennt nur den lokalen Entwicklungs-Host.
     * Über AMEISE_ARCHIVE_API_URL bzw. das Feld in den Einstellungen lässt er
     * sich überschreiben. Für den Live-Betrieb gibt es bewusst keinen Standard:
     * lieber eine klare Fehlermeldung als Requests an den falschen Host.
     */
    private const DEFAULT_TEST_URL = 'https://customer-archives-ameiseapis.inte.dionera.dev';

    private $tokenService;
    private $ameiseLogStatus;
    private $client;
    private $baseUrl;
    private $lastError;
    private $lastStatusCode;

    public function __construct(TokenService $tokenService, ?Client $client = null)
    {
        $this->tokenService = $tokenService;
        $this->ameiseLogStatus = config('ameisemodule.ameise_log_status');
        $this->client = $client ?: new Client();
        $this->baseUrl = $this->resolveBaseUrl();
    }

    /**
     * Ohne konfigurierten Host im Live-Modus ist der Client nicht einsatzbereit.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function getLastStatusCode()
    {
        return $this->lastStatusCode;
    }

    // --- Archiveinträge -----------------------------------------------------

    /**
     * Eintrag ohne Kundenkontext lesen — Basis für den Read-Modify-Write-Zyklus.
     */
    public function getArchiveEntry($archiveEntryId)
    {
        return $this->requestJson('GET', 'archive-entries/' . rawurlencode($archiveEntryId), 'archive_get_entry');
    }

    /**
     * Eintrag lesen und dabei prüfen, ob er wirklich zu diesem Kunden gehört.
     */
    public function getCustomerArchiveEntry($customerId, $archiveEntryId)
    {
        return $this->requestJson(
            'GET',
            $this->customerPath($customerId, 'archive-entries/' . rawurlencode($archiveEntryId)),
            'archive_get_customer_entry'
        );
    }

    /**
     * @param array $filters z. B. ['page' => 1, 'pageSize' => 50, 'dateMin' => '…',
     *                       'types[]' => ['email'], 'contracts[]' => [123]]
     */
    public function listArchiveEntries($customerId, array $filters = [])
    {
        return $this->requestJson(
            'GET',
            $this->customerPath($customerId, 'archive-entries'),
            'archive_list_entries',
            ['query' => $filters]
        );
    }

    public function createArchiveEntry($customerId, array $payload)
    {
        return $this->requestJson(
            'POST',
            $this->customerPath($customerId, 'archive-entries'),
            'archive_create_entry',
            ['json' => $payload]
        );
    }

    /**
     * Erwartet den vollständigen Zielzustand, nicht nur die geänderten Felder.
     */
    public function updateArchiveEntry($customerId, $archiveEntryId, array $payload): bool
    {
        $result = $this->requestJson(
            'PATCH',
            $this->customerPath($customerId, 'archive-entries/' . rawurlencode($archiveEntryId)),
            'archive_update_entry',
            ['json' => $payload]
        );

        return $result !== null;
    }

    public function deleteArchiveEntry($customerId, $archiveEntryId): bool
    {
        $result = $this->requestJson(
            'DELETE',
            $this->customerPath($customerId, 'archive-entries/' . rawurlencode($archiveEntryId)),
            'archive_delete_entry'
        );

        return $result !== null;
    }

    /**
     * @param string|null $module general|metadata|tags|relations
     */
    public function getArchiveEntryLogs($customerId, $archiveEntryId, $module = null, $page = 1, $pageSize = 20)
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ($module !== null && $module !== '') {
            $query['module'] = $module;
        }

        return $this->requestJson(
            'GET',
            $this->customerPath($customerId, 'archive-entries/' . rawurlencode($archiveEntryId) . '/logs'),
            'archive_entry_logs',
            ['query' => $query]
        );
    }

    public function getLatestArchiveEntry($customerId, $tag)
    {
        return $this->requestJson(
            'GET',
            $this->customerPath($customerId, 'archive-entries/latest'),
            'archive_latest_entry',
            ['query' => ['tag' => $tag]]
        );
    }

    /**
     * Übersetzt zwischen der Legacy-ID der Mitarbeiter-API und der UUID der
     * Archive-API. Genau ein Parameter ist zu setzen.
     */
    public function mapArchiveEntryId($legacyId = null, $archiveApiId = null)
    {
        $query = [];
        if ($legacyId !== null && $legacyId !== '') {
            $query['legacyId'] = $legacyId;
        }
        if ($archiveApiId !== null && $archiveApiId !== '') {
            $query['archiveApiId'] = $archiveApiId;
        }
        if (empty($query)) {
            $this->lastError = 'mapArchiveEntryId benötigt legacyId oder archiveApiId.';
            return null;
        }

        return $this->requestJson('GET', 'archive-entries/id-mapping', 'archive_id_mapping', ['query' => $query]);
    }

    /**
     * Liefert den rohen Dateiinhalt, nicht JSON.
     */
    public function downloadArchiveEntryFile($customerId, $archiveEntryId, $fileId)
    {
        $path = $this->customerPath(
            $customerId,
            'archive-entries/' . rawurlencode($archiveEntryId) . '/files/' . rawurlencode($fileId)
        );

        $response = $this->send('GET', $path, 'archive_download_file');
        if ($response === null) {
            return null;
        }

        return (string) $response->getBody();
    }

    // --- Tags ---------------------------------------------------------------

    public function getCustomerTags($customerId)
    {
        return $this->requestJson('GET', $this->customerPath($customerId, 'tags'), 'archive_customer_tags') ?: [];
    }

    public function getCustomersTags()
    {
        return $this->requestJson('GET', 'customers/tags', 'archive_customers_tags') ?: [];
    }

    public function getContractTags($contractId)
    {
        return $this->requestJson(
            'GET',
            'contracts/' . rawurlencode($contractId) . '/tags',
            'archive_contract_tags'
        ) ?: [];
    }

    public function getContractsTags()
    {
        return $this->requestJson('GET', 'contracts/tags', 'archive_contracts_tags') ?: [];
    }

    // --- Interna ------------------------------------------------------------

    private function customerPath($customerId, $suffix): string
    {
        return 'customers/' . rawurlencode($customerId) . '/' . $suffix;
    }

    /**
     * Setzt den Host aus der Konfiguration zusammen; leer bedeutet "nicht nutzbar".
     */
    private function resolveBaseUrl(): string
    {
        $configured = trim((string) config('ameisemodule.ameise_archive_api_url'));
        if ($configured === '') {
            $configured = config('ameisemodule.ameise_mode') == 'test' ? self::DEFAULT_TEST_URL : '';
        }
        if ($configured === '') {
            return '';
        }

        return rtrim($configured, '/') . '/api/';
    }

    /**
     * @return array|null null steht für "fehlgeschlagen", Details in getLastError().
     */
    private function requestJson($method, $path, $logContext, array $options = [])
    {
        $response = $this->send($method, $path, $logContext, $options);
        if ($response === null) {
            return null;
        }

        $body = (string) $response->getBody();
        if (trim($body) === '') {
            // 204 und leere 200er sind für PATCH/DELETE der Normalfall.
            return [];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = __('Die Antwort der Archive-API konnte nicht gelesen werden.');
            $this->ameiseLogStatus && \Helper::log($logContext, 'Antwort ist kein gültiges JSON.');
            return null;
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface|null
     */
    private function send($method, $path, $logContext, array $options = [])
    {
        $this->lastError = null;
        $this->lastStatusCode = null;

        if (!$this->isConfigured()) {
            $this->lastError = __('Es ist keine URL für die Archive-API hinterlegt.');
            \Helper::log($logContext, 'Archive-API nicht konfiguriert (ameise_archive_api_url ist leer).');
            return null;
        }

        $token = $this->tokenService->getAccessToken();
        if (!$this->isUsableToken($token)) {
            $this->lastError = __('Keine gültige Verbindung zur Ameise.');
            $this->ameiseLogStatus && \Helper::log($logContext, 'Request abgebrochen: kein nutzbares Token.');
            return null;
        }

        $url = $this->baseUrl . $path;
        $requestOptions = array_merge([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            // Statuscodes selbst auswerten, statt über Exceptions zu steuern.
            'http_errors' => false,
        ], $options);

        try {
            $this->ameiseLogStatus && \Helper::log($logContext, 'Sending ' . $method . ' request to: ' . $url);
            $response = $this->client->request($method, $url, $requestOptions);
        } catch (RequestException $e) {
            $this->ameiseLogStatus && \Helper::logException($e, $logContext);
            $errorResponse = $e->getResponse();
            if ($errorResponse !== null) {
                $this->lastStatusCode = $errorResponse->getStatusCode();
                $this->ameiseLogStatus && \Helper::log(
                    $logContext,
                    'Error body: ' . $this->sanitizeLogText((string) $errorResponse->getBody())
                );
            }
            $this->lastError = __('Die Archive-API ist nicht erreichbar.');
            return null;
        } catch (\Exception $e) {
            $this->ameiseLogStatus && \Helper::logException($e, $logContext);
            $this->lastError = __('Die Archive-API ist nicht erreichbar.');
            return null;
        }

        $status = $response->getStatusCode();
        $this->lastStatusCode = $status;
        $this->ameiseLogStatus && \Helper::log($logContext, 'Response status: ' . $status);

        if ($status >= 200 && $status < 300) {
            return $response;
        }

        if ($status === 401) {
            // Gleiches Verhalten wie beim CrmApiClient: Verbindung fällt weg,
            // der Nutzer bekommt den roten Ameisen-Button und meldet sich neu an.
            $this->tokenService->disconnectAmeise();
            $this->lastError = __('Die Verbindung zur Ameise ist abgelaufen. Bitte neu verbinden.');
            return null;
        }

        $this->lastError = $this->errorMessageFor($status, (string) $response->getBody());
        $this->ameiseLogStatus && \Helper::log(
            $logContext,
            'Request failed with status code: ' . $status . ' — ' . $this->sanitizeLogText((string) $response->getBody())
        );

        return null;
    }

    /**
     * Der TokenService liefert im Fehlerfall ein JSON mit error/url statt eines Tokens.
     */
    private function isUsableToken($token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $decoded = json_decode($token, true);

        return !(is_array($decoded) && isset($decoded['error']));
    }

    private function errorMessageFor($status, $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (['message', 'detail', 'error'] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }
        }

        if ($status === 403) {
            return __('Keine Berechtigung für diesen Archiveintrag.');
        }
        if ($status === 404) {
            return __('Der Archiveintrag wurde nicht gefunden.');
        }

        return __('Die Archive-API hat die Anfrage abgelehnt (Status :status).', ['status' => $status]);
    }
}
