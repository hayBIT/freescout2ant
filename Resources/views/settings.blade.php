<form class="form-horizontal margin-top margin-bottom" method="POST" action="" enctype="multipart/form-data">
    {{ csrf_field() }}

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('API Mode') }}</label>

        <div class="col-sm-6">
            <select class="form-control" name="settings[ameise_mode]">
                <option value="test" {{ old('settings[ameise_mode]', $settings['ameise_mode']) == 'test' ? 'selected' : '' }}>{{ __('Test') }}</option>
                <option value="live" {{ old('settings[ameise_mode]', $settings['ameise_mode']) == 'live' ? 'selected' : '' }}>{{ __('Live') }}</option>
            </select>
        </div>
    </div>
    <div class="form-group">
      <label for="" class="col-sm-2 control-label">{{ __('Client ID') }}</label>

      <div class="col-sm-6">
          <input class="form-control" name="settings[ameise_client_id]" type="text" value="{{ old('settings[ameise_client_id]', $settings['ameise_client_id'])}}">
      </div>
    </div>

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Client Secret') }}</label>

        <div class="col-sm-6">
            <input class="form-control" name="settings[ameise_client_secret]" type="text" value="{{ old('settings[ameise_client_secret]', $settings['ameise_client_secret'])}}">
        </div>
    </div>
    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Redirect URL') }}</label>

        <div class="col-sm-6">
            <input class="form-control" name="settings[ameise_redirect_uri]" type="text" value="{{ old('settings[ameise_redirect_uri]', $settings['ameise_redirect_uri'])}}" readonly>
        </div>
    </div>

    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save') }}
            </button>
        </div>
    </div>
</form>

@php
    $failedAttempts = \Modules\AmeiseModule\Entities\CrmArchiveAttempt::whereNull('resolved_at')
        ->whereIn('status', \Modules\AmeiseModule\Entities\CrmArchiveAttempt::FAILURE_STATUSES)
        ->where('created_at', '>=', \Carbon\Carbon::now()->subDays(30))
        ->orderBy('created_at', 'desc')
        ->limit(200)
        ->get();
@endphp

<hr>
<h3>{{ __('Archivierungs-Fehler (letzte 30 Tage)') }}</h3>
<p class="text-muted">
    {{ __('Hier erscheinen Threads, die laut Freescout archiviert wurden, in Ameise aber nicht angekommen sind. Cron versucht es alle 5 Minuten erneut; "Erneut versuchen" stoesst den Job sofort an, "Erledigt" markiert alle offenen Versuche fuer diesen Thread als geschlossen.') }}
</p>
@if($failedAttempts->isEmpty())
    <p><em>{{ __('Keine offenen Fehlversuche.') }}</em></p>
@else
    <table class="table table-condensed" id="ameise-failed-attempts">
        <thead>
            <tr>
                <th>{{ __('Konversation') }}</th>
                <th>{{ __('Thread') }}</th>
                <th>{{ __('User') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Versuch') }}</th>
                <th>{{ __('Grund') }}</th>
                <th>{{ __('Zeit') }}</th>
                <th>{{ __('Aktion') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($failedAttempts as $attempt)
            <tr data-attempt-id="{{ $attempt->id }}">
                <td><a href="{{ route('conversations.view', ['id' => $attempt->conversation_id]) }}" target="_blank">#{{ $attempt->conversation_id }}</a></td>
                <td>{{ $attempt->thread_id }}</td>
                <td>{{ $attempt->user_id }}</td>
                <td><code>{{ $attempt->status }}</code></td>
                <td>{{ $attempt->attempt_no }}</td>
                <td title="{{ $attempt->reason }}">{{ \Illuminate\Support\Str::limit($attempt->reason, 80) }}</td>
                <td>{{ $attempt->created_at ? $attempt->created_at->diffForHumans() : '' }}</td>
                <td>
                    <button type="button" class="btn btn-xs btn-default ameise-retry-attempt" data-url="{{ route('ameise.archive_attempts.retry', ['id' => $attempt->id]) }}">{{ __('Erneut versuchen') }}</button>
                    <button type="button" class="btn btn-xs btn-default ameise-dismiss-attempt" data-url="{{ route('ameise.archive_attempts.dismiss', ['id' => $attempt->id]) }}">{{ __('Erledigt') }}</button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<script>
(function(){
    var token = document.querySelector('meta[name="csrf-token"]');
    var csrf = token ? token.getAttribute('content') : '{{ csrf_token() }}';
    function postAction(url) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json'
            }
        });
    }
    document.querySelectorAll('.ameise-retry-attempt').forEach(function(btn){
        btn.addEventListener('click', function(){
            var url = btn.getAttribute('data-url');
            btn.disabled = true;
            postAction(url).then(function(){
                var row = btn.closest('tr');
                if (row) { row.style.opacity = '0.5'; }
                btn.innerText = '{{ __('Eingereiht') }}';
            }).catch(function(){
                btn.disabled = false;
            });
        });
    });
    document.querySelectorAll('.ameise-dismiss-attempt').forEach(function(btn){
        btn.addEventListener('click', function(){
            var url = btn.getAttribute('data-url');
            btn.disabled = true;
            postAction(url).then(function(){
                var row = btn.closest('tr');
                if (row && row.parentNode) { row.parentNode.removeChild(row); }
            }).catch(function(){
                btn.disabled = false;
            });
        });
    });
})();
</script>
