@php
    $ameiseApi = old('settings[ameise_api]', $settings['ameise_api'] ?? 'mitarbeiterwebservice');
    $ameiseIsPublic = filter_var($settings['ameise_archive_is_public'] ?? false, FILTER_VALIDATE_BOOLEAN);
@endphp
<form class="form-horizontal margin-top margin-bottom" method="POST" action="" enctype="multipart/form-data">
    {{ csrf_field() }}

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Archive API') }}</label>

        <div class="col-sm-6">
            <select class="form-control" name="settings[ameise_api]">
                <option value="mitarbeiterwebservice" {{ $ameiseApi == 'mitarbeiterwebservice' ? 'selected' : '' }}>{{ __('MitarbeiterWebservice') }}</option>
                <option value="customer_archives" {{ $ameiseApi == 'customer_archives' ? 'selected' : '' }}>{{ __('Customer Archives') }}</option>
            </select>
            <p class="help-block">{{ __('Choose which Ameise API is used for reading customers/contracts and writing archive entries.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Public archive entries') }}</label>

        <div class="col-sm-6">
            <select class="form-control" name="settings[ameise_archive_is_public]">
                <option value="false" {{ !$ameiseIsPublic ? 'selected' : '' }}>{{ __('No') }}</option>
                <option value="true" {{ $ameiseIsPublic ? 'selected' : '' }}>{{ __('Yes') }}</option>
            </select>
            <p class="help-block">{{ __('Whether archive entries created via the Customer Archives API are visible to the customer (isPublic).') }}</p>
        </div>
    </div>

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
