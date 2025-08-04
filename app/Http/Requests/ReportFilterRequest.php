<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use App\Classes\ApiResponseClass;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => 'nullable|date|before_or_equal:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'client_id' => 'nullable|exists:clients,id',
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:pending,processing,shipped,delivered,cancelled',
            'format' => 'required|in:pdf,excel',
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.date' => 'La fecha de inicio debe ser una fecha válida',
            'start_date.before_or_equal' => 'La fecha de inicio debe ser anterior o igual a la fecha de fin',
            'end_date.date' => 'La fecha de fin debe ser una fecha válida',
            'end_date.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio',
            'client_id.exists' => 'El cliente seleccionado no existe',
            'user_id.exists' => 'El vendedor seleccionado no existe',
            'status.in' => 'El estado debe ser uno de: pending, processing, shipped, delivered, cancelled',
            'format.required' => 'El formato es requerido',
            'format.in' => 'El formato debe ser pdf o excel',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        ApiResponseClass::validationError($validator, $this->validationData());
    }
}