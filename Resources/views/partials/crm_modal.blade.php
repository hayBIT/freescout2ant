<div>
  <div class="text-larger margin-top-10">
  {{ __('Add to ameise') }}
</div>
<form id="crm_user_form">
      <span class="input-group-addon loading-icon" style="display: none;">
          <span class="glyphicon glyphicon-refresh glyphicon-spin" aria-hidden="true"></span>
      </span>
      <input type="text" name="crm_id" id="crm_user" class="form-control">
      <input type="hidden" name="customer_id" id="customer_id" value=""  class="form-control">
      <a  id="crm_button" target="_blank" class="text-large"></a>
      <select name="contracts" class="form-control" style="display: none;width: 400px;"
      id="contract-tag-dropdown" multiple="multiple">
      </select>
      <select name="divisions"  style="display: none;width: 300px"
      id="division-tag-dropdown" multiple="multiple">
      </select>
      <div class="form-group margin-top">
              <button type="button" class="btn btn-primary add-to-calendar-ok" id="archive_btn" style="display: none;">{{ __('Archive') }}</button>
              <button class="btn btn-link" data-dismiss="modal">{{ __('Cancel') }}</button>
          </div>
  </form>
</div>
