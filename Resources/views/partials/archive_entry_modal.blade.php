@if (file_exists(storage_path('user_' . auth()->user()->id . '_ant.txt')))
<div class="modal fade" tabindex="-1" role="dialog" id="ameise-entry-modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Schließen') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ __('Archiveintrag bearbeiten') }}</h4>
            </div>

            <ul class="nav nav-tabs ameise-entry-tabs">
                <li class="active"><a href="#ameise-tab-entry" data-toggle="tab">{{ __('Eintrag') }}</a></li>
                <li><a href="#ameise-tab-relations" data-toggle="tab">{{ __('Zuordnung') }}</a></li>
                <li><a href="#ameise-tab-log" data-toggle="tab">{{ __('Verlauf') }}</a></li>
            </ul>

            <div class="modal-body">
                <div id="ameise-entry-alert" class="alert" style="display:none;"></div>

                <div id="ameise-entry-loading" style="display:none;">
                    <span class="glyphicon glyphicon-refresh glyphicon-spin"></span> {{ __('Eintrag wird geladen …') }}
                </div>

                <div class="tab-content" id="ameise-entry-form" style="display:none;">
                    <div class="tab-pane active" id="ameise-tab-entry">
                        <div class="form-group">
                            <label for="ameise-entry-subject">{{ __('Betreff') }}</label>
                            <input type="text" class="form-control" id="ameise-entry-subject" maxlength="128">
                        </div>

                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label for="ameise-entry-type">{{ __('Typ') }}</label>
                                <select class="form-control" id="ameise-entry-type">
                                    <option value="email">{{ __('E-Mail') }}</option>
                                    <option value="phone">{{ __('Telefon') }}</option>
                                    <option value="document">{{ __('Dokument') }}</option>
                                    <option value="letter">{{ __('Brief') }}</option>
                                    <option value="fax">{{ __('Fax') }}</option>
                                    <option value="sms">{{ __('SMS') }}</option>
                                    <option value="chat">{{ __('Chat') }}</option>
                                    <option value="in_person">{{ __('Persönlich') }}</option>
                                    <option value="information">{{ __('Information') }}</option>
                                    <option value="online">{{ __('Online') }}</option>
                                    <option value="audio">{{ __('Audio') }}</option>
                                    <option value="other">{{ __('Sonstiges') }}</option>
                                </select>
                                <p class="help-block" id="ameise-type-hint" style="display:none;">
                                    {{ __('Der Typ lässt sich nicht ändern, solange Dateien am Eintrag hängen.') }}
                                </p>
                            </div>
                            <div class="col-sm-6 form-group">
                                <label for="ameise-entry-date">{{ __('Datum') }}</label>
                                <input type="text" class="form-control" id="ameise-entry-date" placeholder="TT.MM.JJJJ HH:MM">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="ameise-entry-text">{{ __('Text') }}</label>
                            <textarea class="form-control" id="ameise-entry-text" rows="5"></textarea>
                        </div>

                        <div class="form-group" id="ameise-entry-files-group" style="display:none;">
                            <label>{{ __('Dateien') }}</label>
                            <div id="ameise-entry-files"></div>
                        </div>

                        <div class="checkbox">
                            <label><input type="checkbox" id="ameise-entry-public"> {{ __('Für Kunden sichtbar') }}</label>
                        </div>
                        <div class="checkbox">
                            <label><input type="checkbox" id="ameise-entry-review"> {{ __('Prüfung erforderlich') }}</label>
                        </div>
                    </div>

                    <div class="tab-pane" id="ameise-tab-relations">
                        <div class="form-group">
                            <label>{{ __('Kunde') }}</label>
                            <p class="form-control-static" id="ameise-entry-customer"></p>
                        </div>

                        <div class="form-group">
                            <label for="ameise-entry-contracts">{{ __('Verträge') }}</label>
                            <select class="form-control" id="ameise-entry-contracts" multiple="multiple"></select>
                        </div>

                        <div class="form-group">
                            <label for="ameise-entry-lines">{{ __('Sparten') }}</label>
                            <select class="form-control" id="ameise-entry-lines" multiple="multiple"></select>
                        </div>

                        <div class="form-group">
                            <label for="ameise-entry-tags">{{ __('Tags') }}</label>
                            <select class="form-control" id="ameise-entry-tags" multiple="multiple"></select>
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" id="ameise-entry-apply-all">
                                {{ __('Auf alle Einträge dieser Konversation anwenden') }}
                            </label>
                            <p class="help-block">
                                {{ __('Ersetzt Verträge und Sparten überall; Betreff, Text und Datum bleiben je Eintrag erhalten.') }}
                            </p>
                        </div>
                    </div>

                    <div class="tab-pane" id="ameise-tab-log">
                        <div class="ameise-log-filter">
                            <a href="#" class="ameise-log-module active" data-module="">{{ __('Alle') }}</a>
                            <a href="#" class="ameise-log-module" data-module="general">{{ __('Allgemein') }}</a>
                            <a href="#" class="ameise-log-module" data-module="relations">{{ __('Zuordnungen') }}</a>
                            <a href="#" class="ameise-log-module" data-module="tags">{{ __('Tags') }}</a>
                            <a href="#" class="ameise-log-module" data-module="metadata">{{ __('Metadaten') }}</a>
                        </div>
                        <div id="ameise-entry-log"></div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-link ameise-danger" id="ameise-entry-delete">{{ __('Eintrag löschen') }}</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('Abbrechen') }}</button>
                <button type="button" class="btn btn-primary" id="ameise-entry-save"
                        data-loading-label="{{ __('Wird gespeichert …') }}">{{ __('Speichern') }}</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" tabindex="-1" role="dialog" id="ameise-bulk-modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Schließen') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ __('Zuordnung ändern') }}</h4>
            </div>
            <div class="modal-body">
                <div id="ameise-bulk-alert" class="alert" style="display:none;"></div>

                <div class="form-group">
                    <label for="ameise-bulk-contracts">{{ __('Verträge') }}</label>
                    <select class="form-control" id="ameise-bulk-contracts" multiple="multiple"></select>
                </div>
                <div class="form-group">
                    <label for="ameise-bulk-lines">{{ __('Sparten') }}</label>
                    <select class="form-control" id="ameise-bulk-lines" multiple="multiple"></select>
                </div>

                <div class="radio">
                    <label><input type="radio" name="ameise-bulk-mode" value="replace" checked> {{ __('Bestehende Zuordnung ersetzen') }}</label>
                </div>
                <div class="radio">
                    <label><input type="radio" name="ameise-bulk-mode" value="add"> {{ __('Zur bestehenden Zuordnung hinzufügen') }}</label>
                </div>

                <div id="ameise-bulk-progress" style="display:none;">
                    <div class="ameise-progress-label">{{ __('Einträge werden aktualisiert …') }}</div>
                    <div class="ameise-progress-track"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('Abbrechen') }}</button>
                <button type="button" class="btn btn-primary" id="ameise-bulk-save"
                        data-loading-label="{{ __('Wird übernommen …') }}">{{ __('Übernehmen') }}</button>
            </div>
        </div>
    </div>
</div>

@section('javascripts')
    @parent
    <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/archive_entries.js' }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection
@endif
