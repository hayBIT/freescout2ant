<div>
  <div class="text-larger">
  {{ __('Add to ameise') }}
</div>
<form id="crm_user_form">
    <span>Please search</span>
    <div class="form_user_crm">
        <span class="loading-icon" style="display: none;">
          <span class="glyphicon glyphicon-refresh glyphicon-spin" aria-hidden="true"></span>
      </span>
      <input type="hidden" name="customer_id" id="customer_id" value=""  class="form-control">
      <input type="text" name="crm_id" id="crm_user" class="form-control">
    </div>
    <div>
        <a  id="crm_button" target="_blank" class="text-large"></a>
    </div>
      <div id="contract_block">
          <select name="contracts" class="form-control" style="display: none;"
      id="contract-tag-dropdown" multiple="multiple">
      </select>
      </div>
      <div id="division_block">
          <select name="divisions"  style="display: none;"
      id="division-tag-dropdown" multiple="multiple">
      </select>
      </div>
      <div class="form-group margin-top">
              <button type="button" class="btn btn-primary add-to-calendar-ok" id="archive_btn" style="display: none;">{{ __('Archive') }}</button>
              <button class="btn btn-link" data-dismiss="modal">{{ __('Cancel') }}</button>
          </div>
  </form>
</div>
