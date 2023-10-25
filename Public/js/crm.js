$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    $('#ameise-modal').on('show.bs.modal', function (e) {
        const searchIcon = $(".loading-icon"); 
        const customer_id = $('#customer_id');
        const crm_button = $('#crm_button');
        const archive_btn = $('#archive_btn');

        const input = document.getElementById('crm_user');
        const awesomeList = new Awesomplete(input, { });
        let dataList = [];
        input.addEventListener('input', function () {
        searchIcon.show();
        const inputValue = input.value;
        fetch("/crm/ajax", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: `search=${inputValue}&action=crm_users_search&_token=${csrfToken}`,
                })
            .then(response => response.json())
            .then(data => {
            dataList = data;
            searchIcon.hide();
            const suggestions = data.map(item => ({
                id: item.id, // Assuming "id" is the property containing the ID
                text: item.text // Assuming "text" is the property containing the text
            }));
            input.setAttribute('data-list', suggestions.map(item => item.text).join(','));
            awesomeList.list = suggestions.map(item => item.text);
            awesomeList.evaluate();
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
            mangeContractSelects();
        });
    });

    $('#ameise-modal').on('hidden.bs.modal', function () {
        location.reload();
    });

    function handleSelectChange() {
        let clientId = $('#customer_id').val();
        const storedData = localStorage.getItem(`apiData_${clientId}`);
        const url = '/crm/ajax';

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
                    console.log(data);
                    const storageKey = `apiData_${clientId}`;
                    localStorage.setItem(storageKey, JSON.stringify(data));
                    $('#contract-tag-dropdown, #division-tag-dropdown').empty();
                    populateMultiSelectOptions(data);
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
        formData += '&crm_user_data=' + JSON.stringify(crm_user);
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
            url: '/crm/ajax',
            type: 'POST',
            data: combinedData,
            success: function(response) {
                console.log(response);
                if (response.status) {
                    location.reload();
                }
            },
            error: function(error) {}
        });
    });

    function mangeContractSelects() {
        $('#contract-tag-dropdown, #division-tag-dropdown').select2({
            placeholder: 'Select options',
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
        let coversation = document.getElementById('conv-layout-customer');
        if (document.getElementById('contracts-list')) {
            document.getElementById('contracts-list').remove();
        }
        let conversationId = document.body.getAttribute('data-conversation_id');
        if (coversation) {
        fetch('/crm/'+conversationId+'/get-contracts')
        .then(response => response.text())
        .then(html => {
            // Create a container div to hold the HTML
            if (html.trim() !== '') {
            let container = document.createElement('div');
            container.classList.add('conv-sidebar-block');
            container.style.backgroundColor = '#f8f9f9';
            container.innerHTML = html;

            // Append the container to the "coversation" element
            coversation.append(container);
            }
        })
        .catch(error => {
            console.log("Something went wrong:", error);
        });
        }
    });