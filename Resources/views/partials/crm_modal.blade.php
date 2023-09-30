<div class="modal fade" tabindex="-1" role="dialog" id="ameise-modal">
  <div class="modal-dialog" role="document">
      <div class="modal-content">
          <div class="modal-header">
              <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              <h4 class="modal-title">{{ __('Add to ameise') }}</h4>
          </div>
          <div class="modal-body">
<form id="crm_user_form">
    <span>{{__('Search')}}</span>
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
     
  </form>
</div>
<div class="modal-footer">
  <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('Cancel') }}</button>
  <button type="button" class="btn btn-primary" id="archive_btn" style="display: none;">{{ __('Archive') }}</button>
</div>
</div>
</div>
</div>