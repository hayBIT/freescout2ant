/**
 * Bearbeiten von Ameise-Archiveinträgen aus der Konversation heraus.
 *
 * Vor dem Speichern wird der Eintrag stets frisch geladen; gesendet wird dann
 * der vollständige Zustand. Das verhindert, dass weggelassene Felder Tags und
 * Zuordnungen in der Ameise leeren.
 */
$(document).ready(function () {
    var endpoint = '/ameise/entries/ajax';
    var currentEntry = null;
    var busy = false;

    function csrf() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function post(data) {
        return $.ajax({
            url: endpoint,
            type: 'POST',
            data: $.extend({ _token: csrf() }, data)
        });
    }

    function alertIn(selector, message, type) {
        var box = $(selector);
        if (!message) {
            box.hide().text('');
            return;
        }
        box.removeClass('alert-danger alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .text(message)
            .show();
    }

    function fillSelect(select, options, selected, allowNew) {
        select.empty();
        var chosen = (selected || []).map(String);

        (options || []).forEach(function (option) {
            select.append(new Option(option.text, option.id, false, chosen.indexOf(String(option.id)) !== -1));
        });

        // Bereits gesetzte Werte, die nicht in der Liste stehen, gehen sonst verloren.
        chosen.forEach(function (value) {
            if (!select.find('option[value="' + value + '"]').length) {
                select.append(new Option(value, value, false, true));
            }
        });

        select.select2({
            width: '100%',
            tags: !!allowNew,
            placeholder: select.data('placeholder') || ''
        });
        select.val(chosen).trigger('change');
    }

    function selectedValues(select) {
        return select.val() || [];
    }

    function setBusy(button, running) {
        busy = running;
        if (!button.data('idle-label')) {
            button.data('idle-label', button.text());
        }
        button.text(running ? (button.data('loading-label') || '…') : button.data('idle-label'));
        button.prop('disabled', running);
    }

    // --- Einzelner Eintrag --------------------------------------------------

    $(document).on('click', '.ameise-entry-edit', function (e) {
        e.preventDefault();
        openEntry($(this).data('entry'));
    });

    function openEntry(entryId) {
        currentEntry = null;
        alertIn('#ameise-entry-alert', '');
        $('#ameise-entry-form').hide();
        $('#ameise-entry-loading').show();
        $('#ameise-entry-modal').modal('show');
        $('.ameise-entry-tabs a:first').tab('show');

        post({ action: 'entry_load', entry_id: entryId })
            .done(function (response) {
                $('#ameise-entry-loading').hide();

                if (!response.status) {
                    alertIn('#ameise-entry-alert', response.message);
                    return;
                }

                currentEntry = response.entry;
                renderEntry(response);
                $('#ameise-entry-form').show();
            })
            .fail(function () {
                $('#ameise-entry-loading').hide();
                alertIn('#ameise-entry-alert', 'Der Archiveintrag konnte nicht geladen werden.');
            });
    }

    function renderEntry(response) {
        var entry = response.entry;

        $('#ameise-entry-subject').val(entry.subject || '');
        $('#ameise-entry-text').val(entry.text || '');
        $('#ameise-entry-date').val(entry.date || '');
        $('#ameise-entry-public').prop('checked', entry.is_public);
        $('#ameise-entry-review').prop('checked', entry.requires_review);
        $('#ameise-entry-customer').text(entry.customer_id);

        $('#ameise-entry-type').val(entry.type || 'email')
            .prop('disabled', !!entry.type_locked);
        $('#ameise-type-hint').toggle(!!entry.type_locked);

        var files = $('#ameise-entry-files').empty();
        (entry.files || []).forEach(function (file) {
            files.append($('<div>').addClass('ameise-file').text(file.name));
        });
        $('#ameise-entry-files-group').toggle((entry.files || []).length > 0);

        fillSelect($('#ameise-entry-contracts'), response.relations.contracts, entry.contracts);
        fillSelect($('#ameise-entry-lines'), response.relations.contractLines, entry.contract_lines);
        fillSelect(
            $('#ameise-entry-tags'),
            (response.tagSuggestions || []).map(function (tag) { return { id: tag, text: tag }; }),
            entry.tags,
            true
        );

        $('#ameise-entry-apply-all').prop('checked', false);
        $('#ameise-entry-delete').toggle(!entry.is_deleted);
        $('#ameise-entry-log').empty();
    }

    $(document).on('click', '#ameise-entry-save', function () {
        if (busy || !currentEntry) {
            return;
        }

        var button = $(this);
        setBusy(button, true);
        alertIn('#ameise-entry-alert', '');

        post({
            action: 'entry_update',
            entry_id: currentEntry.id,
            subject: $('#ameise-entry-subject').val(),
            text: $('#ameise-entry-text').val(),
            archive_type: $('#ameise-entry-type').prop('disabled') ? '' : $('#ameise-entry-type').val(),
            date: $('#ameise-entry-date').val(),
            is_public: $('#ameise-entry-public').is(':checked') ? 1 : 0,
            requires_review: $('#ameise-entry-review').is(':checked') ? 1 : 0,
            tags: selectedValues($('#ameise-entry-tags')),
            contracts: selectedValues($('#ameise-entry-contracts')),
            contract_lines: selectedValues($('#ameise-entry-lines')),
            apply_to_conversation: $('#ameise-entry-apply-all').is(':checked') ? 1 : 0
        })
            .done(function (response) {
                setBusy(button, false);
                if (!response.status) {
                    alertIn('#ameise-entry-alert', response.message);
                    return;
                }
                alertIn('#ameise-entry-alert', response.message, 'success');
                refreshSidebar();
            })
            .fail(function () {
                setBusy(button, false);
                alertIn('#ameise-entry-alert', 'Die Änderung wurde nicht gespeichert.');
            });
    });

    $(document).on('click', '#ameise-entry-delete', function () {
        if (busy || !currentEntry) {
            return;
        }
        if (!confirm('Diesen Archiveintrag in der Ameise als gelöscht markieren?')) {
            return;
        }

        var button = $(this);
        setBusy(button, true);

        post({ action: 'entry_update', entry_id: currentEntry.id, is_deleted: 1 })
            .done(function (response) {
                setBusy(button, false);
                alertIn('#ameise-entry-alert', response.message, response.status ? 'success' : 'danger');
                if (response.status) {
                    refreshSidebar();
                }
            })
            .fail(function () {
                setBusy(button, false);
                alertIn('#ameise-entry-alert', 'Der Eintrag konnte nicht gelöscht werden.');
            });
    });

    // --- Verlauf ------------------------------------------------------------

    $(document).on('shown.bs.tab', '.ameise-entry-tabs a[href="#ameise-tab-log"]', function () {
        loadLog('');
    });

    $(document).on('click', '.ameise-log-module', function (e) {
        e.preventDefault();
        $('.ameise-log-module').removeClass('active');
        $(this).addClass('active');
        loadLog($(this).data('module'));
    });

    function loadLog(module) {
        if (!currentEntry) {
            return;
        }

        var target = $('#ameise-entry-log').html('<span class="text-muted">Wird geladen …</span>');

        post({ action: 'entry_logs', entry_id: currentEntry.id, module: module })
            .done(function (response) {
                target.empty();

                if (!response.status) {
                    target.append($('<div>').addClass('text-danger').text(response.message));
                    return;
                }
                if (!response.items.length) {
                    target.append($('<div>').addClass('text-muted').text('Keine Einträge im Verlauf.'));
                    return;
                }

                response.items.forEach(function (item) {
                    var row = $('<div>').addClass('ameise-log-row');
                    row.append($('<span>').addClass('ameise-log-when').text((item.modifiedAt || '').substring(0, 16).replace('T', ' ')));

                    var what = $('<span>');
                    what.append($('<b>').text(item.attribute || ''));
                    if (item.author && item.author.displayName) {
                        what.append(document.createTextNode(' · ' + item.author.displayName));
                    }
                    what.append($('<br>'));
                    if (item.oldValue) {
                        what.append($('<del>').text(item.oldValue));
                        what.append(document.createTextNode(' '));
                    }
                    if (item.newValue) {
                        what.append($('<ins>').text(item.newValue));
                    }
                    row.append(what);
                    target.append(row);
                });
            })
            .fail(function () {
                target.html('<div class="text-danger">Der Verlauf konnte nicht geladen werden.</div>');
            });
    }

    // --- Zuordnung für die ganze Konversation -------------------------------

    $(document).on('click', '.ameise-bulk-relations', function (e) {
        e.preventDefault();

        var link = $(this);
        $('#ameise-bulk-modal')
            .data('conversation', link.data('conversation'))
            .data('customer', link.data('customer'));

        alertIn('#ameise-bulk-alert', '');
        $('#ameise-bulk-progress').hide();
        $('#ameise-bulk-modal').modal('show');

        // Die Auswahllisten kommen über einen beliebigen Eintrag des Kunden.
        var entryId = link.closest('.conversation-archives').find('.ameise-entry-edit').first().data('entry');
        if (!entryId) {
            alertIn('#ameise-bulk-alert', 'Für diesen Kunden ist noch kein Eintrag zugeordnet.', 'info');
            return;
        }

        post({ action: 'entry_load', entry_id: entryId }).done(function (response) {
            if (!response.status) {
                alertIn('#ameise-bulk-alert', response.message);
                return;
            }
            fillSelect($('#ameise-bulk-contracts'), response.relations.contracts, response.entry.contracts);
            fillSelect($('#ameise-bulk-lines'), response.relations.contractLines, response.entry.contract_lines);
        });
    });

    $(document).on('click', '#ameise-bulk-save', function () {
        if (busy) {
            return;
        }

        var button = $(this);
        var modal = $('#ameise-bulk-modal');

        setBusy(button, true);
        alertIn('#ameise-bulk-alert', '');
        $('#ameise-bulk-progress').show();

        post({
            action: 'bulk_relations',
            conversation_id: modal.data('conversation'),
            contracts: selectedValues($('#ameise-bulk-contracts')),
            contract_lines: selectedValues($('#ameise-bulk-lines')),
            mode: $('input[name="ameise-bulk-mode"]:checked').val()
        })
            .done(function (response) {
                setBusy(button, false);
                $('#ameise-bulk-progress').hide();
                alertIn('#ameise-bulk-alert', response.message, response.status ? 'success' : 'danger');
                (response.errors || []).forEach(function (error) {
                    $('#ameise-bulk-alert').append($('<div>').addClass('small').text(error));
                });
                refreshSidebar();
            })
            .fail(function () {
                setBusy(button, false);
                $('#ameise-bulk-progress').hide();
                alertIn('#ameise-bulk-alert', 'Die Zuordnung wurde nicht übernommen.');
            });
    });

    // --- Auflösen bei Bedarf ------------------------------------------------

    var resolveTried = false;

    $(document).on('click', '.ameise-resolve', function (e) {
        e.preventDefault();
        resolveConversation($(this).data('conversation'), true);
    });

    /**
     * Nur die Einträge der geöffneten Konversation werden aufgelöst — ein
     * Aufruf, statt den gesamten Bestand vorab durchzuarbeiten.
     */
    function resolveConversation(conversationId, force) {
        if (!conversationId || (resolveTried && !force)) {
            return;
        }
        resolveTried = true;

        post({ action: 'resolve_conversation', conversation_id: conversationId })
            .done(function (response) {
                if (response.status && response.resolved > 0) {
                    refreshSidebar();
                }
            });
    }

    // Die Seitenleiste wird nachgeladen; sobald sie da ist und Einträge ohne
    // Zuordnung zeigt, wird einmalig nachgeschlagen.
    var watcher = setInterval(function () {
        var unmapped = $('.ameise-badge.unmapped');
        if (!unmapped.length) {
            return;
        }
        clearInterval(watcher);
        resolveConversation(document.body.getAttribute('data-conversation_id'), false);
    }, 1000);
    setTimeout(function () { clearInterval(watcher); }, 15000);

    // --- Seitenleiste -------------------------------------------------------

    function refreshSidebar() {
        var conversationId = document.body.getAttribute('data-conversation_id');
        if (!conversationId) {
            return;
        }

        $.get('/ameise/' + conversationId + '/get-contracts', function (html) {
            var list = $('#contracts-list').closest('.conv-sidebar-block');
            if (list.length && html.trim() !== '') {
                list.html(html);
            }
        });
    }
});
