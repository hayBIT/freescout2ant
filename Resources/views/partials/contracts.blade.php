@if($archives->isNotEmpty())
<div class="panel-group accordion accordion-empty">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" href=".collapse-conv-ameise">{{ __('Ameise Archivierungen') }}
                    <b class="caret"></b>
                </a>
            </h4>
        </div>
        <div class="collapse-conv-ameise panel-collapse collapse in">
            <div class="conversation-contracts panel-body" id="contracts-list">
                @if ($archives->isNotEmpty() && file_exists(storage_path('user_' . auth()->user()->id . '_ant.txt')))
                    <div class="conversation-archives-data">
                        @foreach ($archives as $archive)
                            @php
                                // Sicherstellen, dass crm_user decodiert werden kann
                                $user = [];
                                $malformed = false;
                                if ($archive->crm_user) {
                                    $decoded = json_decode($archive->crm_user, true);
                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        $decoded = json_decode(urldecode($archive->crm_user), true);
                                        $malformed = true;
                                    }
                                    if (!$decoded && preg_match('/"text"\s*:\s*"([^"]*)/', $archive->crm_user, $m)) {
                                        $decoded = ['id' => $archive->crm_user_id, 'text' => $m[1]];
                                        $malformed = true;
                                    }
                                    $user = is_array($decoded) ? $decoded : [];
                                }
                                if (empty($user) || $malformed) {
                                    $tokenService = new \Modules\AmeiseModule\Services\TokenService('', auth()->user()->id);
                                    $client = new \Modules\AmeiseModule\Services\CrmApiClient($tokenService);
                                    $resp = $client->fetchUserByIdOrName($archive->crm_user_id);
                                    if (is_array($resp) && count($resp) > 0) {
                                        $user = ['id' => $resp[0]['Id'], 'text' => $resp[0]['Text']];
                                    }
                                }
                            @endphp

                            <div class="conversation-archives">
                                <a style="font-size:14px;" target="_blank"
                                   href="{{ (config('ameisemodule.ameise_mode') == 'test' ? 'https://maklerinfo.inte.dionera.dev' : 'https://ameise.app') }}/maklerportal/?show=kunde&kunde={{ $user['id'] ?? '' }}">
                                    <p>{{ $user['text'] ?? '' }}</p>
                                </a>

                                @php
                                    $contracts = $archive->contracts ? json_decode($archive->contracts, true) : [];
                                @endphp
                                @if (!empty($contracts))
                                    @foreach ($contracts as $contract)
                                        <div class="contract-tag">
                                            <span class="tag-text glyphicon glyphicon-file"></span>{{ $contract['text'] ?? '' }}
                                        </div>
                                    @endforeach
                                @endif

                                @php
                                    $divisions = $archive->divisions ? json_decode($archive->divisions, true) : [];
                                @endphp
                                @if (!empty($divisions))
                                    @foreach ($divisions as $division)
                                        <div class="division-tag">
                                            <span class="tag-text glyphicon glyphicon-circle"></span>{{ $division['text'] ?? '' }}
                                        </div>
                                    @endforeach
                                @endif

                                @php
                                    $customerEntries = $entries->get((string) $archive->crm_user_id, collect());
                                @endphp
                                @if ($customerEntries->isNotEmpty())
                                    <div class="ameise-entries">
                                        <div class="ameise-entries-head">
                                            <span class="ameise-entries-title">{{ $customerEntries->count() }} {{ $customerEntries->count() === 1 ? __('Archiveintrag') : __('Archiveinträge') }}</span>
                                            <span class="ameise-entries-actions">
                                                <a href="#" class="ameise-bulk-relations"
                                                   data-conversation="{{ $archive->conversation_id }}"
                                                   data-customer="{{ $archive->crm_user_id }}">{{ __('Zuordnung ändern') }}</a>
                                                <a href="#" class="ameise-resolve" title="{{ __('Zuordnung in der Ameise nachschlagen') }}"
                                                   data-conversation="{{ $archive->conversation_id }}">&#8635;</a>
                                            </span>
                                        </div>

                                        @foreach ($customerEntries as $entry)
                                            <div class="ameise-entry" data-entry="{{ $entry->id }}">
                                                <span class="ameise-entry-icon glyphicon {{ $entry->kind === 'attachment' ? 'glyphicon-file' : ($entry->entry_type === 'telefon' ? 'glyphicon-earphone' : 'glyphicon-envelope') }}"></span>
                                                <span class="ameise-entry-body">
                                                    <span class="ameise-entry-subject">{{ $entry->subject ?: __('(Kein Betreff)') }}</span>
                                                    @if ($entry->requires_review)
                                                        <span class="ameise-badge review">{{ __('Prüfung') }}</span>
                                                    @endif
                                                    @if (isset($entry->is_public) && !$entry->is_public)
                                                        <span class="ameise-badge internal">{{ __('intern') }}</span>
                                                    @endif
                                                    @if ($entry->is_deleted)
                                                        <span class="ameise-badge deleted">{{ __('gelöscht') }}</span>
                                                    @endif
                                                    @if (!$entry->archive_entry_id)
                                                        <span class="ameise-badge unmapped" title="{{ $entry->last_error }}">{{ __('nicht zugeordnet') }}</span>
                                                    @endif
                                                    <span class="ameise-entry-meta">{{ $entry->entry_date ? $entry->entry_date->format('d.m.Y H:i') : '' }}</span>
                                                </span>
                                                @if ($entry->archive_entry_id)
                                                    <a href="#" class="ameise-entry-edit" data-entry="{{ $entry->id }}"
                                                       title="{{ __('Archiveintrag bearbeiten') }}"><span class="glyphicon glyphicon-pencil"></span></a>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
