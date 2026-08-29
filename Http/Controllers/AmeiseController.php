<?php

namespace Modules\AmeiseModule\Http\Controllers;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\AmeiseModule\Services\TokenService;
use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\ConversationArchiver;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

class AmeiseController extends Controller
{
    protected $tokenService;
    protected $apiClient;
    protected $archiver;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->tokenService = $this->tokenService ?? new TokenService('', auth()->user()->id);
            $this->apiClient = new CrmApiClient($this->tokenService);
            $this->archiver = new ConversationArchiver($this->apiClient);
            return $next($request);
        });

    }

    public function refreshToken()
    {
        $this->tokenService = $this->tokenService ?? new TokenService('', auth()->user()->id);
        $this->tokenService->getAccessToken();
        return response()->json(['status' => 'ok']);
    }
    /**
     *  @return Response Crm ajax controller.
     */
    public function ajax(Request $request)
    {
        $inputs = $request->all();
        switch ($request->action) {
            case 'crm_users_search':
                $results = [];
                if(!empty($inputs['new_conversation'])) {
                    $results = $this->getFSUsers($inputs);
                }
                return $this->getCrmUsers($inputs, $results);
                break;

            case 'get_contract':
                $response = $this->apiClient->getContracts($request->input('client_id'));
                if (isset($response['error']) && isset($response['url'])) {
                    return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
                }
                $divisionResponse = $this->apiClient->getContactEndPoints('sparten');
                $statusResponse = $this->apiClient->getContactEndPoints('vertragsstatus');
                $groupedData = collect($response)->groupBy('Status')->map(function ($group) use ($divisionResponse, $statusResponse) {
                    return $group->map(function ($items) use ($divisionResponse, $statusResponse) {
                        $divisionKey = array_search($items['Sparte'], array_column($divisionResponse, 'Value'));
                        $statusKey = array_search($items['Status'], array_column($statusResponse, 'Value'));
                        $divisionText = ($divisionKey !== false) ? $divisionResponse[$divisionKey]['Text'] : null;
                        $statusText = ($statusKey !== false) ? $statusResponse[$statusKey]['Text'] : null;
                        return [
                            'id' => $items['Id'],
                            'Risiko' => $items['Risiko'],
                            'Versicherungsscheinnummer' => $items['Versicherungsscheinnummer'],
                            'Sparte' => $divisionText,
                            'key' => $statusText,
                        ];
                    });
                });
                return response()->json(['contracts' => $groupedData, 'divisions' => $divisionResponse]);
                break;
            case 'crm_conversation_archive':
                $conversation = Conversation::with('threads.all_attachments')->find($inputs['conversation_id']);
                if (!$conversation) {
                    return response()->json(['status' => false, 'message' => __('Konversation nicht gefunden.')]);
                }

                $crm_archive = CrmArchive::where(
                    ['conversation_id' => $inputs['conversation_id'],
                    'crm_user_id' => $inputs['customer_id']
                    ])->first();

                $crm_user_id = $inputs['customer_id'];
                $contracts = json_decode($inputs['contracts'] ?? '', true);
                $divisions = json_decode($inputs['divisions_data'] ?? '', true);
                $scanOnly = $this->archiver->isScanOnly($conversation);

                // Erst archivieren, dann speichern: die Zuordnung darf in FreeScout
                // nur entstehen, wenn die Archivierung in der Ameise geklappt hat.
                $allArchived = true;
                $archivedThreadIds = [];
                $excludedSenders = [];
                foreach($conversation->threads as $thread) {
                    if ($crm_archive) {
                        $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crm_archive->id)->where('thread_id',$thread->id)->first();
                        if ($isArchiveThread) {
                            continue;
                        }
                    }
                    if (!$this->archiver->shouldArchiveThread($conversation, $thread)) {
                        continue;
                    }
                    // Absender, die in den Einstellungen ausgeschlossen sind,
                    // werden nicht archiviert.
                    $excludedSender = $this->archiver->getExcludedSender($conversation, $thread);
                    if ($excludedSender !== null) {
                        $excludedSenders[$excludedSender] = $excludedSender;
                        continue;
                    }
                    $conversation_data = $this->archiver->createConversationData($conversation, $crm_user_id, $contracts, $divisions, $thread);
                    $archived = $scanOnly ? true : $this->apiClient->archiveConversation($conversation_data);
                    $attachmentsArchived = $archived ? $this->archiver->archiveConversationWithAttachments($thread, $conversation_data) : false;
                    if($archived && (!$scanOnly || $attachmentsArchived)) {
                        $archivedThreadIds[] = $thread->id;
                    } else {
                        $allArchived = false;
                    }
                }

                // Nichts konnte archiviert werden: weder eine neue Zuordnung anlegen
                // noch eine bestehende Zuordnung überschreiben.
                if (!$allArchived && empty($archivedThreadIds)) {
                    \Helper::log(
                        'conversation_archive',
                        'Zuordnung nicht gespeichert, da die Archivierung fehlgeschlagen ist (conversation_id: '
                            . $conversation->id . ', crm_user_id: ' . $crm_user_id . ').'
                    );

                    return response()->json([
                        'status' => false,
                        'message' => __('Die Archivierung in der Ameise ist fehlgeschlagen. Die Zuordnung wurde nicht gespeichert.'),
                    ]);
                }

                // Es gab nur ausgeschlossene Absender: nichts archivieren, nichts
                // zuordnen - der Benutzer bekommt eine Mitteilung.
                if (empty($archivedThreadIds) && !empty($excludedSenders)) {
                    \Helper::log(
                        'conversation_archive',
                        'Archivierung übersprungen, da die Absenderadresse ausgeschlossen ist ('
                            . implode(', ', $excludedSenders) . ', conversation_id: ' . $conversation->id . ').'
                    );

                    return response()->json([
                        'status' => false,
                        'notice' => __(
                            'Die Archivierung wurde übersprungen: Die Absenderadresse :sender ist in den Ameise-Einstellungen von der Archivierung ausgeschlossen.',
                            ['sender' => implode(', ', $excludedSenders)]
                        ),
                    ]);
                }

                if(!$crm_archive) {
                    $crm_archive = new CrmArchive();
                    $crm_archive->crm_user_id = $crm_user_id;
                    $crm_archive->conversation_id = $inputs['conversation_id'];
                    $crm_archive->archived_by = auth()->user()->id;
                }
                $crm_archive->crm_user = $inputs['crm_user_data'] ?? null;
                $crm_archive->contracts = $inputs['contracts'] ?? null;
                $crm_archive->divisions = $inputs['divisions_data'] ?? null;
                $crm_archive->save();

                foreach ($archivedThreadIds as $archivedThreadId) {
                    CrmArchiveThread::create(['crm_archive_id' => $crm_archive->id,'thread_id' => $archivedThreadId,'conversation_id'=> $conversation->id ]);
                }

                $response = ['status' => $allArchived];
                if (!$allArchived) {
                    // Teilerfolg: die bereits archivierten Threads bleiben zugeordnet.
                    $response['message'] = __('Nicht alle Nachrichten konnten in der Ameise archiviert werden.');
                }
                if (!empty($excludedSenders)) {
                    // Einzelne Nachrichten wurden wegen des Absenders übersprungen.
                    $response['notice'] = __(
                        'Nicht alle Nachrichten wurden archiviert: Die Absenderadresse :sender ist in den Ameise-Einstellungen von der Archivierung ausgeschlossen.',
                        ['sender' => implode(', ', $excludedSenders)]
                    );
                }

                return response()->json($response);
                break;

        }

    }

    public function getContracts($id)
    {
        if(!Conversation::find($id)) {
            return '';
        }
        $archives = CrmArchive::where('conversation_id', $id)->orderBy('id', 'DESC')->get();
        if(!$archives) {
            return false;
        }
        return view('ameise::partials.contracts', [
            'archives' => $archives,
        ])->render();

    }

    private function getCrmUsers($inputs, $result = [])
    {
        $response = [];
        if (!empty($inputs['search_by_mail']) && !empty($inputs['conversation_id'])) {
            $conversation = Conversation::with('threads')->find($inputs['conversation_id']);
            if (!empty($conversation->customer_email)) {
                $response = $this->apiClient->fetchUserByEmail($conversation->customer_email);
                if (isset($response['error']) && isset($response['url'])) {
                    return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
                }
            }
            if ($conversation) {
                $customerNumbers = $this->extractCustomerNumbersFromConversation($conversation);
                foreach ($customerNumbers as $customerNumber) {
                    $customerResponse = $this->apiClient->fetchUserByIdOrName($customerNumber);
                    if (isset($customerResponse['error']) && isset($customerResponse['url'])) {
                        return response()->json(['error' => 'Redirect', 'url' => $customerResponse['url']]);
                    }
                    if (!empty($customerResponse)) {
                        $response = array_merge($response, $customerResponse);
                    }
                }
            }
            $response = $this->uniqueCrmUsersById($response);
        }

        if (empty($response)) {
            $search = trim($inputs['search'] ?? '');
            if ($search === '') {
                $result['crmUsers'] = [];
                return response()->json($result);
            }
            $response = $this->apiClient->fetchUserByIdOrName($search);
        }

        if (isset($response['error']) && isset($response['url'])) {
            return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
        }
        $crmUsers = [];
        foreach($response as $data) {
            $emails = $phone =  [];
            $contactDetails = $this->apiClient->fetchUserDetail($data['Id'], 'kontaktdaten');
            foreach ($contactDetails as $item) {
                if ($item["Typ"] === "email") {
                    $emails[] = $item["Value"];
                } elseif($item['Typ'] == 'telefon') {
                    $phone [] = $item['Value'];
                }
            }
            $crmUsers[] = [
                'id' => $data['Id'],
                'text' => $data['Text'],
                'id_name' => $data['Person']['Vorname'] . " " . $data['Person']['Nachname'] . "(" . $data['Id'] . ")",
                'first_name' => $data['Person']['Vorname'],
                'last_name'  => $data['Person']['Nachname'],
                'address'    => $data['Hauptwohnsitz']['Strasse'],
                'zip'        => $data['Hauptwohnsitz']['Postleitzahl'],
                'city'       => $data['Hauptwohnsitz']['Ort'],
                'country'    => $data['Hauptwohnsitz']['Land'],
                'emails'     => $emails,
                'phones'     => $phone,
            ];
        }
        $result['crmUsers'] = $crmUsers;
        return response()->json($result);
    }

    private function extractCustomerNumbersFromConversation($conversation)
    {
        $searchableTexts = [];
        if (!empty($conversation->subject)) {
            $searchableTexts[] = $conversation->subject;
        }

        foreach ($conversation->threads as $thread) {
            if (!empty($thread->body)) {
                $searchableTexts[] = $thread->body;
            }
        }

        $customerNumbers = [];

        foreach ($searchableTexts as $text) {
            $numbers = $this->extractCustomerNumbers((string) $text);
            if (!empty($numbers)) {
                $customerNumbers = array_merge($customerNumbers, $numbers);
            }
        }

        return array_values(array_unique($customerNumbers));
    }

    private function extractCustomerNumbers($text)
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

    private function uniqueCrmUsersById(array $users)
    {
        $uniqueUsers = [];
        foreach ($users as $user) {
            if (isset($user['Id'])) {
                $uniqueUsers[$user['Id']] = $user;
            }
        }

        return array_values($uniqueUsers);
    }

    private function getFSUsers($inputs)
    {
        $response = [];
        $q = $inputs['search'];
        $customers_query = \App\Customer::select(['customers.id', 'first_name', 'last_name', 'emails.email'])->join('emails', 'customers.id', '=', 'emails.customer_id');
        $customers_query->where('emails.email', 'like', '%'.$q.'%');
        $customers_query->orWhere('first_name', 'like', '%'.$q.'%')
            ->orWhere('last_name', 'like', '%'.$q.'%');
        $customers = $customers_query->paginate(20);
        foreach ($customers as $customer) {
            $id = '';
            $text = $customer->getNameAndEmail();
            $id = $customer->email;

            $response['fsUsers'][] = [
                'id'   => $id,
                'text' => $text,
            ];
        }
        return $response;
    }
}
