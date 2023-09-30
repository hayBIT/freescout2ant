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
            <input class="form-control" name="settings[ameise_redirect_uri]" type="text" value="{{ old('settings[ameise_redirect_uri]', $settings['ameise_redirect_uri'])}}">
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
