$(document).ready(function() {
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
 // Initialize a single Select2 instance for email address selection
function initializeSelect2(context) {
    let options = {
        ajax: {
            url: '/ameise/ajax',
            type: 'POST',
            dataType: 'json',
            data: function(params) {
                return {
                    search: params.term,
                    action: 'crm_users_search',
                    new_conversation: true,
                    _token: csrfToken
                };
            },
            processResults: function(data,params) {
                if (data.error === 'Redirect') {
                    window.open(data.url, '_blank');
                }
                if (data.length === 0) {
                    let inputValue = params.term;
                    let emailRegex = /^[A-Za-z0-9._%-]+@[A-Za-z0-9.-]+/;
                    let isEmailValid = emailRegex.test(inputValue);
                    let existingOptions = context.find('option');
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
                    let results = [];

                    if (data.crmUsers && data.crmUsers.length > 0) {
                        let crmUsersGroup = {
                            text: 'Ameise Users',
                            children: []
                        };
                        data.crmUsers.forEach(function(user) {
                            crmUsersGroup.children.push({
                                id: user.id,
                                text: user.text,
                                disabled: user.emails.length === 0,
                                children: user.emails.map(function(email_data) {
                                    return {
                                        id: email_data,
                                        text: email_data,
                                        record: user
                                    };
                                }),
                            });
                        });
                        results.push(crmUsersGroup);
                    }

                    if (data.fsUsers && data.fsUsers.length > 0) {
                        let fsUsersGroup = {
                            text: 'Other Users',
                            children: []
                        };
                        data.fsUsers.forEach(function(user) {
                            fsUsersGroup.children.push({
                                id: user.id,
                                text: user.text
                            });
                        });
                        results.push(fsUsersGroup);
                    }

                    return { results: results };                    
                }
            }
        },
        minimumInputLength: 2,
        placeholder: 'Searching...',
        allowClear: true,
        containerCssClass: "select2-multi-container", // select2-with-loader
        dropdownCssClass: "select2-multi-dropdown",
    };
    //Add new email on the spot
   	let token_separators = [",", ", ", " "];
    $.extend(options, {
        multiple: true,
        tags: true,
        tokenSeparators: token_separators,
        createTag: function (params) {
            // Don't allow to create a tag if there is no @ symbol
            if (!/^.+@.+$/.test(params.term)) {
                // Return null to disable tag creation
                return null;
            }
            // Check if select already has such option
            let data = this.select2('data');
            console.log(data);
            for (i in data) {
                if (data[i].id == params.term) {
                    return null;
                }
            }
            return {
                id: params.term,
                text: params.term,
                newOption: true
            }
        }.bind(context),
        templateResult: function (data) {
            let $result = $("<span></span>");

            $result.text(data.text);

            if (data.newOption) {
                $result.append(" <em>("+Lang.get("messages.add_lower")+")</em>");
            }

            return $result;
        }
    });
    context.select2(options);   
}
 
// Initialize Select2 for CC
initializeSelect2($('#cc'));

// Initialize Select2 for BCC
initializeSelect2($('#bcc'));

// Initialize Select2 for TO
initializeSelect2($('#to'));


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

  // Construct the link URL based on the ameiseMode value
  let ameise_base_url = $('#ameise_base_url').val();

  var linkUrl = ameise_base_url + 'maklerportal/?show=kunde&kunde=';

 
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
        const selectedUser = e.params.data;
        if (!selectedUser || !selectedUser.record) {
            return; 
        }
        callHtml();
        const recipientType = selector.substring(1); // Extract recipient type from selector
        displayUserDetails(selectedUser, recipientType);
    });
});


});