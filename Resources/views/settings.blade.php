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

    <hr>
    <h4 class="col-sm-offset-2">{{ __('Automatische Zuordnung') }}</h4>

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Automatisch zuordnen') }}</label>

        <div class="col-sm-6">
            @php
                // FILTER_VALIDATE_BOOLEAN akzeptiert 'true'/'false' ebenso wie ältere
                // '1'/'0'-Werte, die vor diesem Fix in der .env gelandet sein können.
                $ameiseAutoAssign = filter_var(old('settings[ameise_auto_assign]', $settings['ameise_auto_assign']), FILTER_VALIDATE_BOOLEAN);
            @endphp
            <select class="form-control" name="settings[ameise_auto_assign]">
                <option value="false" {{ $ameiseAutoAssign ? '' : 'selected' }}>{{ __('Nein') }}</option>
                <option value="true" {{ $ameiseAutoAssign ? 'selected' : '' }}>{{ __('Ja') }}</option>
            </select>
            <p class="help-block">
                {{ __('Konversationen werden nur bei eindeutigem Treffer (E-Mail-Adresse oder Kundennummer) automatisch archiviert. Ein falscher Archiveintrag kann in Ameise nicht gelöscht werden – zuvor mit "php artisan ameise:auto-assign --dry-run" prüfen.') }}
            </p>
        </div>
    </div>

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Verträge/Sparten zuordnen') }}</label>

        <div class="col-sm-6">
            @php
                $ameiseAutoAssignContracts = filter_var(old('settings[ameise_auto_assign_contracts]', $settings['ameise_auto_assign_contracts']), FILTER_VALIDATE_BOOLEAN);
            @endphp
            <select class="form-control" name="settings[ameise_auto_assign_contracts]">
                <option value="false" {{ $ameiseAutoAssignContracts ? '' : 'selected' }}>{{ __('Nein') }}</option>
                <option value="true" {{ $ameiseAutoAssignContracts ? 'selected' : '' }}>{{ __('Ja') }}</option>
            </select>
            <p class="help-block">{{ __('Nur wenn die Versicherungsscheinnummer im Text steht oder der Kunde genau einen Vertrag hat.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Service-Nutzer') }}</label>

        <div class="col-sm-6">
            @php
                $ameiseConnectedUsers = \App\User::orderBy('first_name')->get()->filter(function ($user) {
                    return \Modules\AmeiseModule\Services\TokenService::isConnected($user->id);
                });
            @endphp
            <select class="form-control" name="settings[ameise_service_user_id]">
                <option value="">{{ __('— nicht gesetzt —') }}</option>
                @foreach ($ameiseConnectedUsers as $ameiseUser)
                    <option value="{{ $ameiseUser->id }}" {{ (string) old('settings[ameise_service_user_id]', $settings['ameise_service_user_id']) === (string) $ameiseUser->id ? 'selected' : '' }}>
                        {{ $ameiseUser->getFullName() }} ({{ $ameiseUser->email }})
                    </option>
                @endforeach
            </select>
            <p class="help-block">
                {{ __('Ameise-Zugang, unter dem eingehende Nachrichten archiviert werden. Es werden nur Nutzer angezeigt, die bereits mit Ameise verbunden sind.') }}
                @if ($ameiseConnectedUsers->isEmpty())
                    <br><strong>{{ __('Aktuell ist kein Nutzer mit Ameise verbunden – die automatische Zuordnung bleibt inaktiv.') }}</strong>
                @endif
            </p>
        </div>
    </div>

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Mailboxen') }}</label>

        <div class="col-sm-6">
            <input class="form-control" name="settings[ameise_auto_assign_mailboxes]" type="text" value="{{ old('settings[ameise_auto_assign_mailboxes]', $settings['ameise_auto_assign_mailboxes']) }}" placeholder="{{ __('z. B. 1,3 – leer = alle') }}">
            <p class="help-block">{{ __('Kommagetrennte Mailbox-IDs, auf die die Automatik beschränkt wird.') }}</p>
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
