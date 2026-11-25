<?php

namespace App\Http\Requests;

use App\Classes\ApiResponseClass;
use App\Http\Requests\Concerns\ProductValidationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ProductStoreRequest extends FormRequest
{
    use ProductValidationRules;

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
        return array_merge($this->sharedProductRules(), [
            'quantity' => 'nullable|integer|min:0|max:1000000',
            'purchase_price' => 'required|numeric|min:0.01|max:1000000',
            'sale_price' => 'required|numeric|min:0.01|max:1000000',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $validator->errors()->any()) {
                $this->validateCoherence($validator, null, true);
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        ApiResponseClass::validationError($validator, []);
    }

    public function messages(): array
    {
        return $this->sharedProductMessages();
    }
}
