@if($archives)
<div class="conversation-contracts" id="contracts-list">
    @if ($archives->isNotEmpty() && file_exists(storage_path('user_' . auth()->user()->id . '_ant.txt')))
        <div class="conversation-archives-data">
            @foreach ($archives as $archive)
                <div class="conversation-archives">
                    <p>
                        @php
                            $user = json_decode($archive->crm_user, true);
                        @endphp
                        <a style="font-size:16px;" target="_blank"
                            href="{{ (config('ameisemodule.ameise_mode') == 'test' ? 'https://maklerinfo.inte.dionera.dev' : 'https://maklerinfo.biz') }}/maklerportal/?show=kunde&kunde={{ $user['id'] }}">{{ $user['text'] }}</a>
                    </p>
                    @php
                        $contracts = json_decode($archive->contracts, true);
                    @endphp
                    @if ($contracts)
                        @foreach ($contracts as $contract)
                            <span class="contract-tag">
                                <span class="tag-text glyphicon glyphicon-file">{{ $contract['text'] }}</span></span>
                        @endforeach
                    @endif

                    @php
                        $divisions = json_decode($archive->divisions, true);
                    @endphp
                    @if ($divisions)
                        @foreach ($divisions as $division)
                            <span class="division-tag">
                                <span class="tag-text glyphicon glyphicon-circle">{{ $division['text'] }}
                                </span></span>
                        @endforeach
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endif
