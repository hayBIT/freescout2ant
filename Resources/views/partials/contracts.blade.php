@if($archives->isNotEmpty())
<div class="panel-group accordion accordion-empty">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" href=".collapse-conv-ameise">{{ __('Ameise Contracts') }}
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
                                $user = $archive->crm_user ? json_decode($archive->crm_user, true) : [];
                            @endphp

                            <div class="conversation-archives">
                                <a style="font-size:14px;" target="_blank"
                                   href="{{ (config('ameisemodule.ameise_mode') == 'test' ? 'https://maklerinfo.inte.dionera.dev' : 'https://www.maklerinfo.biz') }}/maklerportal/?show=kunde&kunde={{ $user['id'] ?? '' }}">
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
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
