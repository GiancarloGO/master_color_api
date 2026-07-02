<?php

namespace App\Http\Requests;

use App\Classes\ApiResponseClass;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $categoryId = $this->route('category');

        return [
            'name'   => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->ignore($categoryId),
            ],
            'active' => 'nullable|boolean',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        ApiResponseClass::validationError($validator, []);
    }

    public function messages(): array
    {
        return [
            'name.required'  => 'El nombre de la categoría es obligatorio.',
            'name.string'    => 'El nombre debe ser un texto válido.',
            'name.max'       => 'El nombre no puede tener más de 255 caracteres.',
            'name.unique'    => 'Ya existe una categoría con ese nombre.',
            'active.boolean' => 'El estado debe ser verdadero o falso.',
        ];
    }
}
