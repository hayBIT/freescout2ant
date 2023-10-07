$(document).ready(function() {
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  $('#to').select2({
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
          processResults: function(data) {
              return {
                  results: data.map(function(item) {
                      return {
                          id: item.emails[0], // Assuming Value is the email value
                          text: item.id_name,
                          record: item,
                      };
                  })
              };
          }
      },
      minimumInputLength: 2,
      placeholder: 'Searching...',
      escapeMarkup: function(markup) {
          return markup;
      },
      templateResult: function(item) {
          return item.text;
      },
      templateSelection: function(item) {
          return item.id; // Display the email value
      },
  });

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

  // Add an event listener for the 'select2:select' event
  $('#to').on('select2:select', function(e) {
      callHtml();
      const selectedUser = e.params.data; // User object
      const userId = selectedUser.record.id; // Unique user identifier
      const userDetailDiv = document.createElement('div');
      userDetailDiv.classList.add(userId); // Add the user's class to the element

      userDetailDiv.innerHTML += `
      <div class="user-data">
          <a style="font-size:14px;" target="_blank" href="${linkUrl + selectedUser.record.id}">
              ${selectedUser.text}
          </a>
      </div>`;
      // Check if user data exists before adding it
      if (selectedUser.record.first_name && selectedUser.record.last_name) {
          userDetailDiv.innerHTML += `<div class="user-data">User Name: ${selectedUser.record.first_name} ${selectedUser.record.last_name}</div>`;
      }

      if (selectedUser.record.email) {
          userDetailDiv.innerHTML += `<div class="user-data">Email: ${selectedUser.record.email}</div>`;
      }

      if (selectedUser.record.address) {
          userDetailDiv.innerHTML += `<div class="user-data">Address: ${selectedUser.record.address}</div>`;
      }
      // Check if 'phones' is an array and not empty
      if (Array.isArray(selectedUser.record.phones) && selectedUser.record.phones.length > 0) {
          userDetailDiv.innerHTML += `<div class="user-data">Phones: ${selectedUser.record.phones.join(', ')}</div>`;
      }

      // Check if userDetailDiv has any content before appending it
      if (userDetailDiv.innerHTML.trim()) {
          const usersList = document.getElementById('users-list');
          usersList.innerHTML = ''; // Clear previous user details
          usersList.appendChild(userDetailDiv);
      }
  });

});