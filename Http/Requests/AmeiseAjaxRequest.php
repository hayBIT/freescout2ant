<?php

namespace Modules\AmeiseModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AmeiseAjaxRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        $rules = [
            'action' => 'required|string|in:crm_users_search,get_contract,crm_conversation_archive',
        ];

        switch ($this->input('action')) {
            case 'crm_users_search':
                $rules['search'] = 'required|string|max:255';
                $rules['new_conversation'] = 'nullable|string';
                break;

            case 'get_contract':
                $rules['client_id'] = 'required|string|max:255';
                break;

            case 'crm_conversation_archive':
                $rules['conversation_id'] = 'required|integer|exists:conversations,id';
                $rules['customer_id'] = 'required|string|max:255';
                $rules['crm_user_data'] = 'required|string';
                $rules['contracts'] = 'nullable|string';
                $rules['divisions_data'] = 'nullable|string';
                break;
        }

        return $rules;
    }
}
