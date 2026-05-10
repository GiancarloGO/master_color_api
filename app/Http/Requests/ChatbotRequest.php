<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatbotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message'          => 'required|string|max:500',
            'history'          => 'array|max:20',
            'history.*.role'   => 'required|string|in:user,assistant',
            'history.*.content'=> 'required|string|max:1000',
            'session_id'       => 'required|uuid',
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'El mensaje es requerido.',
            'message.max'      => 'El mensaje no puede superar los 500 caracteres.',
            'session_id.uuid'  => 'La sesión no es válida.',
        ];
    }
}
