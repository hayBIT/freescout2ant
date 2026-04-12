$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    const input = document.getElementById('crm_user');
    let awesomeList = null;
    let dataList = [];
    let emailSuggestionsCache = null;
    let listenersAttached = false;

    if (input) {
        awesomeList = new Awesomplete(input, {
            minChars: 0,
            filter: function(text, input) {
                if (input.trim() === '') return true;
                return Awesomplete.FILTER_CONTAINS(text, input);
            }
        });
    }

    function showSuggestions(users) {
        if (!awesomeList || !users || users.length === 0) return;
        dataList = users;
        $(".loading-icon").hide();
        awesomeList.list = users.map(function(item) { return item.text; });
        input.focus();
        awesomeList.evaluate();
    }

    // Use shown.bs.modal so the modal is fully visible before showing suggestions
    $('#ameise-modal').on('shown.bs.modal', function (e) {
        // Show cached email suggestions if available
        if (emailSuggestionsCache && emailSuggestionsCache.length > 0) {
            showSuggestions(emailSuggestionsCache);
        }
    });

    $('#ameise-modal').on('show.bs.modal', function (e) {
        const searchIcon = $(".loading-icon");
        const customer_id = $('#customer_id');
        const crm_button = $('#crm_button');
        const archive_btn = $('#archive_btn');
        emailSuggestionsCache = null;

        if (!listenersAttached && input) {
            listenersAttached = true;

            input.addEventListener('input', function () {
                searchIcon.show();
                var inputValue = input.value;
                fetch("/ameise/ajax", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: "search=" + encodeURIComponent(inputValue) + "&action=crm_users_search&_token=" + encodeURIComponent(csrfToken),
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.error === 'Redirect') {
                        window.open(data.url, '_blank');
                    } else {
                        showSuggestions(data.crmUsers || []);
                    }
                })
                .catch(function() { $('#result').html('An error occurred while fetching data.'); });
            });

            input.addEventListener('awesomplete-selectcomplete', function (e) {
                var selectedValue = e.text.value;
                var ameise_base_url = $('#ameise_base_url').val();
                var selectedObject = dataList.find(function(item) { return item.text === selectedValue; });
                $('#contract-tag-dropdown, #division-tag-dropdown').empty();
                customer_id.val(selectedObject.id);
                crm_button.show().text(selectedValue).
                attr('href', ameise_base_url + 'maklerportal/?show=kunde&kunde=' + selectedObject.id);
                archive_btn.show();
                $("#contract-tag-dropdown, #division-tag-dropdown").show();
                manageContractSelects();
            });
        }

        // Auto-search by customer email on modal open
        var customerEmail = $('#ameise_customer_email').val();
        if (customerEmail) {
            searchIcon.show();
            fetch("/ameise/ajax", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: "email=" + encodeURIComponent(customerEmail) + "&action=crm_email_search&_token=" + encodeURIComponent(csrfToken),
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.error === 'Redirect') {
                    window.open(data.url, '_blank');
                } else if (data.crmUsers && data.crmUsers.length > 0) {
                    // Cache results; shown.bs.modal will display them when modal is visible
                    emailSuggestionsCache = data.crmUsers;
                    // If modal is already visible, show immediately
                    if ($('#ameise-modal').hasClass('in')) {
                        showSuggestions(data.crmUsers);
                    }
                } else {
                    searchIcon.hide();
                }
            })
            .catch(function() {
                searchIcon.hide();
            });
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

        $.ajax({
            url: '/ameise/ajax',
            type: 'POST',
            data: combinedData,
            success: function(response) {
                console.log(response);
                if (response.status) {
                    location.reload();
                } else if(response.error == 'Redirect'){
                    window.open(response.url, '_blank');
                }
            },
            error: function(error) {}
        });
    });

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
