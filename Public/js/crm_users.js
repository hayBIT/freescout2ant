$(document).ready(function() {
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
 // Initialize a single Select2 instance for email address selection
function initializeSelect2(context) {

    $(context).select2({
        tokenSeparators: [',', ' '],
        createTag: function(params) {
            return {
                id: params.term,
                text: params.term,
                newTag: true
            };
        },
        ajax: {
            url: '/crm/ajax',
            type: 'POST',
            dataType: 'json',
            data: function(params) {
                return {
                    search: params.term,
                    action: 'crm_users_search',
                    _token: csrfToken
                };
            },
            processResults: function(data,params) {
                if (data.length === 0) {
                    var inputValue = params.term;
                    let emailRegex = /^[A-Za-z0-9._%-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}$/;
                    let isEmailValid = emailRegex.test(inputValue);
                    let existingOptions = $(context).find('option');
                    if (isEmailValid && !existingOptions.is('[value="' + inputValue + '"]')) {
                        data.push({
                            id: inputValue,
                            text: inputValue
                        });
                        return {
                            results: data
                        };
                    }
                    
                } else {
                    return {
                        results: data.map(function(item) {
                            return {
                                id: item.id,
                                text: item.text,
                                disabled: item.emails.length === 0,
                                children: item.emails.map(function(email_data) {
                                    return {
                                        id: email_data,
                                        text: email_data,
                                        record: item
                                    };
                                }),
                            };
                        })
                    };
                }
            }
        },
        minimumInputLength: 2,
        placeholder: 'Searching...',
        allowClear: true
    });
   
}


// Initialize Select2 for CC
initializeSelect2('#cc');

// Initialize Select2 for BCC
initializeSelect2('#bcc');

// Initialize Select2 for TO
initializeSelect2('#to');





  let coversation = document.getElementById('conv-layout-customer');
  if (document.getElementById('user-panel-list')) {
      document.getElementById('user-panel-list').remove();
  }

  function callHtml() {
      coversation.innerHTML = `<div class="crm-user-panel panel-group accordion accordion-empty" id="user-panel-list">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <a data-toggle="collapse" href=".collapse-conv-ameise">User Detail
                        <b class="caret"></b>
                    </a>
                </h4>
            </div>
            <div class="user-panel-body collapse-conv-ameise panel-collapse collapse in">
                <div class="panel-body" id="users-list">
                </div>
            </div>
        </div>
    </div>`;
  }

  var ameiseMode = "{{ config('ameisemodule.ameise_mode') }}";


  // Construct the link URL based on the ameiseMode value
  var linkUrl = (ameiseMode === 'test' ? 'https://maklerinfo.inte.dionera.dev' : 'https://maklerinfo.biz') + '/maklerportal/?show=kunde&kunde=';

 
  // Define a common function to display user details
function displayUserDetails(selectedUser, recipientType) {
    const userId = selectedUser.record.id;
    const userDetailDiv = document.createElement('div');
    userDetailDiv.classList.add(userId);

    userDetailDiv.innerHTML += `
        <div class="user-data">
            <a style="font-size:14px;" target="_blank" href="${linkUrl + selectedUser.record.id}">
                ${selectedUser.record.text}
            </a>
        </div>`;

    if (selectedUser.record.first_name && selectedUser.record.last_name) {
        userDetailDiv.innerHTML += `<div class="user-data">${translations.userName}: ${selectedUser.record.first_name} ${selectedUser.record.last_name}</div>`;
    }

    if (selectedUser.record.email) {
        userDetailDiv.innerHTML += `<div class="user-data">${translations.email}: ${selectedUser.record.email}</div>`;
    }

    if (selectedUser.record.address) {
        userDetailDiv.innerHTML += `<div class="user-data">${translations.address}: ${selectedUser.record.address}</div>`;
    }

    if (Array.isArray(selectedUser.record.phones) && selectedUser.record.phones.length > 0) {
        userDetailDiv.innerHTML += `<div class="user-data">${translations.phones}: ${selectedUser.record.phones.join(', ')}</div>`;
    }

    // Check if userDetailDiv has any content before appending it
    if (userDetailDiv.innerHTML.trim()) {
        const usersList = document.getElementById('users-list');
        usersList.innerHTML = ''; // Clear previous user details
        usersList.appendChild(userDetailDiv);
    }

    // Apply specific behavior based on the recipient type
    // Additional recipient-specific behavior can be added here
}

// Set up event delegation for all recipient types
const recipientSelectors = ['#to', '#cc', '#bcc'];

recipientSelectors.forEach((selector) => {
    $(selector).on('select2:select', function (e) {
        callHtml();
        const selectedUser = e.params.data;
        const recipientType = selector.substring(1); // Extract recipient type from selector
        displayUserDetails(selectedUser, recipientType);
    });
});


});