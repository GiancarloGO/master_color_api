<?php

namespace App\Http\Requests;

use App\Classes\ApiResponseClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientId = $this->route('client');
        
        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('clients', 'email')->ignore($clientId)
            ],
            'password' => 'sometimes|string|min:8',
            'client_type' => 'sometimes|required|string|in:individual,company',
            'document_type' => 'sometimes|required|string|in:DNI,RUC,CE,PASAPORTE',
            'identity_document' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('clients', 'identity_document')->ignore($clientId)
            ],
            'phone' => [
                'nullable',
                'string',
                Rule::unique('clients', 'phone')->ignore($clientId)
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        ApiResponseClass::validationError($validator, $this->validationData());
    }

    public function messages()
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.string' => 'El nombre debe ser una cadena de texto.',
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',
            
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe ser válido.',
            'email.unique' => 'El correo electrónico ya está en uso.',
            'email.max' => 'El correo electrónico no puede tener más de 255 caracteres.',
            
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            
            'client_type.required' => 'El tipo de cliente es obligatorio.',
            'client_type.in' => 'El tipo de cliente debe ser individual o company.',
            
            'document_type.required' => 'El tipo de documento es obligatorio.',
            'document_type.in' => 'El tipo de documento debe ser DNI, RUC, CE o PASAPORTE.',
            
            'identity_document.required' => 'El documento de identidad es obligatorio.',
            'identity_document.unique' => 'El documento de identidad ya está en uso.',
            
            'phone.unique' => 'El teléfono ya está en uso.',
        ];
    }
}