<?php

namespace App\Http\Requests;

use App\Classes\ApiResponseClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class ClientRegisterRequest extends FormRequest
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
        return [
            // Datos del cliente
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:clients',
            'password' => 'required|string|min:8|confirmed',
            'client_type' => 'required|string|in:individual,company',
            'document_type' => 'required|string|in:dni,ruc',
            'identity_document' => 'required|string|unique:clients',
            'phone' => 'nullable|string|unique:clients',
            
            // Datos de dirección de entrega
            'address_full' => 'required|string|max:500',
            'district' => 'required|string|max:100',
            'province' => 'required|string|max:100',
            'department' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'reference' => 'nullable|string|max:500',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        ApiResponseClass::validationError($validator, $this->validationData());
    }

    public function messages()
    {
        return [
            // Mensajes para datos del cliente
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe ser válido.',
            'email.unique' => 'El correo electrónico ya está en uso.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'client_type.required' => 'El tipo de cliente es obligatorio.',
            'client_type.in' => 'El tipo de cliente debe ser individual o company.',
            'document_type.required' => 'El tipo de documento es obligatorio.',
            'document_type.in' => 'El tipo de documento debe ser dni o ruc.',
            'identity_document.required' => 'El documento de identidad es obligatorio.',
            'identity_document.unique' => 'El documento de identidad ya está en uso.',
            'phone.unique' => 'El celular ya está en uso.',
            
            // Mensajes para datos de dirección
            'address_full.required' => 'La dirección completa es obligatoria.',
            'address_full.max' => 'La dirección no puede tener más de 500 caracteres.',
            'district.required' => 'El distrito es obligatorio.',
            'district.max' => 'El distrito no puede tener más de 100 caracteres.',
            'province.required' => 'La provincia es obligatoria.',
            'province.max' => 'La provincia no puede tener más de 100 caracteres.',
            'department.required' => 'El departamento es obligatorio.',
            'department.max' => 'El departamento no puede tener más de 100 caracteres.',
            'postal_code.max' => 'El código postal no puede tener más de 10 caracteres.',
            'reference.max' => 'La referencia no puede tener más de 500 caracteres.',
        ];
    }
}
