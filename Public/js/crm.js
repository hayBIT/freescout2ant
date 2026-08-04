$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    let archiveInProgress = false;

    $('#ameise-modal').on('show.bs.modal', function (e) {
        const searchIcon = $(".loading-icon"); 
        const customer_id = $('#customer_id');
        const crm_button = $('#crm_button');
        const archive_btn = $('#archive_btn');

        showArchiveError('');
        setArchiveRunning(false);

        const input = document.getElementById('crm_user');
        const awesomeList = new Awesomplete(input, {
            minChars: 0,
            autoFirst: true
        });
        let dataList = [];

        function applySuggestions(data) {
            dataList = data.crmUsers || [];
            searchIcon.hide();
            const suggestions = dataList.map(item => ({
                id: item.id,
                text: item.text
            }));
            input.setAttribute('data-list', suggestions.map(item => item.text).join(','));
            awesomeList.list = suggestions.map(item => item.text);
            awesomeList.evaluate();
        }

        function loadSuggestionsByConversationEmail() {
            const conversationId = document.body.getAttribute('data-conversation_id');
            if (!conversationId) {
                return;
            }
            searchIcon.show();
            fetch("/ameise/ajax", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: `search=&search_by_mail=1&conversation_id=${encodeURIComponent(conversationId)}&action=crm_users_search&_token=${encodeURIComponent(csrfToken)}`,
            })
                .then(response => response.json())
                .then(data => {
                    if (data.error === 'Redirect') {
                        window.open(data.url, '_blank');
                        return;
                    }
                    applySuggestions(data);
                })
                .catch(() => searchIcon.hide());
        }

        loadSuggestionsByConversationEmail();

        input.addEventListener('input', function () {
        searchIcon.show();
        const inputValue = input.value;
        fetch("/ameise/ajax", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: `search=${encodeURIComponent(inputValue)}&action=crm_users_search&_token=${encodeURIComponent(csrfToken)}`,
                })
            .then(response => response.json())
            .then(data => {
                if (data.error === 'Redirect') {
                    // Redirect in the current tab
                    window.open(data.url, '_blank');

                } else {
                    applySuggestions(data);
                }
            })
            .catch(error => $('#result').html('An error occurred while fetching data.'));
        });
    
        input.addEventListener('awesomplete-selectcomplete', function (e) {
            const selectedValue = e.text.value;
            let ameise_base_url = $('#ameise_base_url').val();
            const selectedObject = dataList.find(item => item.text === selectedValue);
            $('#contract-tag-dropdown, #division-tag-dropdown').empty();
            customer_id.val(selectedObject.id);
            crm_button.show().text(selectedValue).
            attr('href', `${ameise_base_url}maklerportal/?show=kunde&kunde=${selectedObject.id}`);
            archive_btn.show();
            $("#contract-tag-dropdown, #division-tag-dropdown").show();
            manageContractSelects();
        });
    });

    $('#ameise-modal').on('shown.bs.modal', function () {
        // Cursor direkt ins Suchfeld setzen, damit man sofort lostippen kann.
        $('#crm_user').trigger('focus');
    });

    $('#ameise-modal').on('hide.bs.modal', function (e) {
        // Solange archiviert wird, darf der Dialog nicht zugehen: das Schließen
        // lädt die Seite neu und würde den laufenden Request abbrechen.
        if (archiveInProgress) {
            e.preventDefault();
        }
    });

    $('#ameise-modal').on('hidden.bs.modal', function () {
        location.reload();
    });

    function handleSelectChange() {
        let clientId = $('#customer_id').val();
        const storedData = localStorage.getItem(`apiData_${clientId}`);
        const url = '/ameise/ajax';

        if (!storedData) {
            $.ajax({
                url: url,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    client_id: clientId,
                    action: 'get_contract',
                    _token: csrfToken
                }),
                success: function(data) {
                    if (data.error === 'Redirect') {
                        // Redirect in the current tab
                        window.open(data.url, '_blank');
    
                    } else {
                    const storageKey = `apiData_${clientId}`;
                    localStorage.setItem(storageKey, JSON.stringify(data));
                    $('#contract-tag-dropdown, #division-tag-dropdown').empty();
                    populateMultiSelectOptions(data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                },
            });
        }
    }

    function populateMultiSelectOptions(data) {
        const multiSelect = $('#contract-tag-dropdown');
        const multiSelect1 = $('#division-tag-dropdown');

        for (const key in data.contracts) {
            if (data.contracts.hasOwnProperty(key)) {
                const group = data.contracts[key];
                const groupKey = group[0].key;
                const $optgroup = $('<optgroup>', {
                    label: groupKey
                });
                group.forEach(option => {
                    const optionText = `${option.Sparte} - ${option.Versicherungsscheinnummer} - ${option.Risiko}`;
                    const newOption = new Option(optionText, option.id);
                    $optgroup.append(newOption);
                });
                multiSelect.append($optgroup);
            }
        }

        data.divisions.forEach(option => {
            const newOption = new Option(option.Text, option.Value);
            multiSelect1.append(newOption);
        });

        multiSelect.select2();
        multiSelect1.select2();
    }
    $(document).on("click", '#archive_btn', function() {
        if (archiveInProgress) {
            return;
        }

        let formData = [];
        formData = $('#crm_user_form').serialize();
        let crm_user = {
            'id': $('#customer_id').val(),
            'text': $('#crm_button').text()
        }
        let conversationId = $(".body-conv").attr("data-conversation_id");
        let csrfToken = $('meta[name="csrf-token"]').attr('content');
        formData += '&_token=' + encodeURIComponent(csrfToken);
        formData += '&conversation_id=' + conversationId;
        formData += '&crm_user_data=' + encodeURIComponent(JSON.stringify(crm_user));
        formData += '&action=' + 'crm_conversation_archive';

        let combinedData = formData;

        function processSelectedData(selectedData, paramName) {
            if (selectedData) {
                let jsonData = selectedData.map(function(option) {
                    return {
                        id: option.id,
                        text: option.text
                    };
                });

                let formDataObject = {};
                formDataObject[paramName] = JSON.stringify(jsonData);

                let jsonQueryString = $.param(formDataObject);
                combinedData += (combinedData ? '&' : '') + jsonQueryString;
            }
        }
        processSelectedData($('#contract-tag-dropdown').select2('data'), 'contracts');
        processSelectedData($('#division-tag-dropdown').select2('data'), 'divisions_data');

        showArchiveError('');
        setArchiveRunning(true);

        $.ajax({
            url: '/ameise/ajax',
            type: 'POST',
            data: combinedData,
            success: function(response) {
                if (response.status) {
                    // Animation weiterlaufen lassen, bis der Reload greift.
                    location.reload();
                    return;
                }
                setArchiveRunning(false);
                if(response.error == 'Redirect'){
                    window.open(response.url, '_blank');
                } else {
                    // Archivierung fehlgeschlagen: die Zuordnung wurde serverseitig
                    // nicht gespeichert, deshalb hier auch nicht neu laden.
                    showArchiveError(response.message || 'Die Archivierung in der Ameise ist fehlgeschlagen. Die Zuordnung wurde nicht gespeichert.');
                }
            },
            error: function(error) {
                setArchiveRunning(false);
                showArchiveError('Die Archivierung in der Ameise ist fehlgeschlagen. Die Zuordnung wurde nicht gespeichert.');
            }
        });
    });

    function showArchiveError(message) {
        const errorBox = $('#ameise-archive-error');
        if (!message) {
            errorBox.hide().text('');
            return;
        }
        errorBox.text(message).show();
    }

    // Archivieren kann je nach Anzahl der Nachrichten und Anhänge dauern.
    // Solange die Anfrage läuft, zeigt der Dialog Spinner und Fortschrittsbalken.
    function setArchiveRunning(running) {
        archiveInProgress = running;

        const archiveBtn = $('#archive_btn');
        if (!archiveBtn.data('idle-label')) {
            archiveBtn.data('idle-label', archiveBtn.text());
        }

        if (running) {
            const loadingLabel = archiveBtn.data('loading-label') || 'Archivierung läuft …';
            archiveBtn.empty()
                .append($('<span>', { 'class': 'ameise-spinner', 'aria-hidden': 'true' }))
                .append(document.createTextNode(loadingLabel));
        } else {
            archiveBtn.text(archiveBtn.data('idle-label'));
        }

        archiveBtn.prop('disabled', running).attr('aria-busy', running ? 'true' : 'false');
        $('#archive_cancel_btn, #ameise-modal .modal-header .close').prop('disabled', running);
        $('#ameise-archive-progress').toggle(running);
    }

    function manageContractSelects() {
        $('#contract-tag-dropdown').select2({
            placeholder: 'Verträge',
            width: '350px',
            tokenSeparators: [',', ' '],
            createTag: function(params) {
                return {
                    id: params.term,
                    text: params.term,
                    newTag: true
                };
            },
        });

        $('#division-tag-dropdown').select2({
            placeholder: 'Sparten',
            width: '350px',
            tokenSeparators: [',', ' '],
            createTag: function(params) {
                return {
                    id: params.term,
                    text: params.term,
                    newTag: true
                };
            },
        });

        getContracts();
    }

    function getContracts() {
        let clientId = $('#customer_id').val();
        window.addEventListener('beforeunload', function() {
            localStorage.removeItem(`apiData_${clientId}`);
        });

        if (clientId.trim() !== '') {
            handleSelectChange();
        }
    }
});

    window.addEventListener('DOMContentLoaded', (event) => {
        let conversation = document.getElementById('conv-layout-customer');
        if (document.getElementById('contracts-list')) {
            document.getElementById('contracts-list').remove();
        }
        let conversationId = document.body.getAttribute('data-conversation_id');
        if (conversation) {
        fetch('/ameise/'+conversationId+'/get-contracts')
        .then(response => response.text())
        .then(html => {
            // Create a container div to hold the HTML
            if (html.trim() !== '') {
            let container = document.createElement('div');
            container.classList.add('conv-sidebar-block');
            container.style.backgroundColor = '#f8f9f9';
            container.innerHTML = html;

            // Append the container to the "conversation" element
            conversation.append(container);
            }
        })
        .catch(error => {
            console.log("Something went wrong:", error);
        });
        }
    });
