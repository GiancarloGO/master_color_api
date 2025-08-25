<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentLookupRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                'in:dni,ruc',
            ],
            'document' => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                function ($attribute, $value, $fail) {
                    $documentType = $this->input('type');
                    
                    if ($documentType === 'dni' && strlen($value) !== 8) {
                        $fail('El DNI debe tener exactamente 8 dígitos.');
                    }
                    
                    if ($documentType === 'ruc' && strlen($value) !== 11) {
                        $fail('El RUC debe tener exactamente 11 dígitos.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de documento es requerido.',
            'type.in' => 'El tipo de documento debe ser dni o ruc.',
            'document.required' => 'El número de documento es requerido.',
            'document.regex' => 'El número de documento solo puede contener dígitos.',
        ];
    }
}