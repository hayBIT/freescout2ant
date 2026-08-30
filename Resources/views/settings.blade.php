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
        <label for="" class="col-sm-2 control-label">{{ __('Archive API URL') }}</label>

        <div class="col-sm-6">
            <input class="form-control" name="settings[ameise_archive_api_url]" type="text" value="{{ old('settings[ameise_archive_api_url]', $settings['ameise_archive_api_url'])}}" placeholder="https://customer-archives-ameiseapis.inte.dionera.dev">
            <p class="help-block">{{ __('Host der Archive-API zum Bearbeiten von Archiveinträgen. Im Test-Modus kann das Feld leer bleiben, im Live-Modus muss es gesetzt sein.') }}</p>
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

    <div class="form-group">
        <label for="ameise_excluded_senders" class="col-sm-2 control-label">{{ __('Ausgeschlossene Absender') }}</label>

        <div class="col-sm-6">
            <textarea class="form-control" id="ameise_excluded_senders" name="settings[ameise_excluded_senders]" rows="5" placeholder="newsletter@example.com&#10;*@no-reply.example.com">{{ old('settings.ameise_excluded_senders', $settings['ameise_excluded_senders']) }}</textarea>
            <p class="help-block">
                {{ __('Eine Absenderadresse pro Zeile. E-Mails dieser Absender werden nicht in der Ameise archiviert; der Benutzer erhält stattdessen eine Mitteilung.') }}
                {{ __('Platzhalter sind erlaubt (z. B. *@example.com). Ein Eintrag ohne lokalen Teil (z. B. @example.com) schließt die komplette Domain aus.') }}
            </p>
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
