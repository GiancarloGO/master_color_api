<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ProductValidationRules
{
    /**
     * Reglas compartidas entre creación y actualización de productos.
     *
     * @return array<string, mixed>
     */
    protected function sharedProductRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')->ignore($ignoreId)->whereNull('deleted_at')],
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($ignoreId)],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($ignoreId)],
            'brand' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'presentation' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'unidad' => 'required|string|max:255',
            // Meses de garantía por defecto (se copia a cada unidad vendida). 0 = sin garantía.
            'default_warranty_months' => 'nullable|integer|min:0|max:120',
            'min_stock' => 'nullable|integer|min:0|max:100000',
            'max_stock' => 'nullable|integer|min:0|max:1000000',
        ];
    }

    /**
     * Valida coherencia entre precios de compra/venta y topes de stock.
     */
    protected function validateCoherence(Validator $validator, ?Product $existing = null, bool $isCreate = false): void
    {
        $purchase = $this->input('purchase_price') ?? $existing?->stock?->purchase_price;
        $sale = $this->input('sale_price') ?? $existing?->stock?->sale_price;

        if (is_numeric($purchase) && is_numeric($sale) && (float) $sale <= (float) $purchase) {
            $validator->errors()->add('sale_price', 'El precio de venta debe ser mayor al precio de compra.');
        }

        $min = (int) ($this->input('min_stock') ?? $existing?->stock?->min_stock ?? 0);
        $max = (int) ($this->input('max_stock') ?? $existing?->stock?->max_stock ?? 0);

        if ($max > 0 && $max <= $min) {
            $validator->errors()->add('max_stock', 'El stock máximo debe ser mayor al stock mínimo.');
        }

        if ($isCreate) {
            $qty = (int) ($this->input('quantity') ?? 0);
            if ($qty < $min) {
                $validator->errors()->add('quantity', 'La cantidad inicial debe ser al menos igual al stock mínimo.');
            }
            if ($max > 0 && $qty > $max) {
                $validator->errors()->add('quantity', 'La cantidad inicial no puede exceder el stock máximo.');
            }
        }
    }

    /**
     * Mensajes de validación en español compartidos entre Store y Update.
     *
     * @return array<string, string>
     */
    protected function sharedProductMessages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.string' => 'El nombre debe ser un texto válido.',
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',
            'name.unique' => 'Ya existe un producto con este nombre.',

            'sku.required' => 'El SKU es obligatorio.',
            'sku.string' => 'El SKU debe ser un texto válido.',
            'sku.max' => 'El SKU no puede tener más de 255 caracteres.',
            'sku.unique' => 'Este SKU ya existe en el sistema.',

            'barcode.string' => 'El código de barras debe ser un texto válido.',
            'barcode.max' => 'El código de barras no puede tener más de 255 caracteres.',
            'barcode.unique' => 'Este código de barras ya existe en el sistema.',

            'brand.string' => 'La marca debe ser un texto válido.',
            'brand.max' => 'La marca no puede tener más de 255 caracteres.',

            'description.string' => 'La descripción debe ser un texto válido.',
            'description.max' => 'La descripción no puede tener más de 2000 caracteres.',

            'presentation.string' => 'La presentación debe ser un texto válido.',
            'presentation.max' => 'La presentación no puede tener más de 255 caracteres.',

            'category.required' => 'La categoría es obligatoria.',
            'category.string' => 'La categoría debe ser un texto válido.',
            'category.max' => 'La categoría no puede tener más de 255 caracteres.',

            'unidad.required' => 'La unidad es obligatoria.',
            'unidad.string' => 'La unidad debe ser un texto válido.',
            'unidad.max' => 'La unidad no puede tener más de 255 caracteres.',

            'image.image' => 'El archivo debe ser una imagen válida.',
            'image.mimes' => 'La imagen debe ser de tipo: jpeg, png, jpg o webp.',
            'image.max' => 'La imagen no puede ser mayor a 5MB.',

            'default_warranty_months.integer' => 'Los meses de garantía deben ser un número entero.',
            'default_warranty_months.min' => 'Los meses de garantía no pueden ser negativos.',
            'default_warranty_months.max' => 'Los meses de garantía no pueden exceder 120.',

            'quantity.integer' => 'La cantidad debe ser un número entero.',
            'quantity.min' => 'La cantidad no puede ser negativa.',
            'quantity.max' => 'La cantidad inicial no puede exceder 1,000,000 unidades.',

            'min_stock.integer' => 'El stock mínimo debe ser un número entero.',
            'min_stock.min' => 'El stock mínimo no puede ser negativo.',
            'min_stock.max' => 'El stock mínimo no puede exceder 100,000 unidades.',

            'max_stock.integer' => 'El stock máximo debe ser un número entero.',
            'max_stock.min' => 'El stock máximo no puede ser negativo.',
            'max_stock.max' => 'El stock máximo no puede exceder 1,000,000 unidades.',

            'purchase_price.required' => 'El precio de compra es obligatorio.',
            'purchase_price.numeric' => 'El precio de compra debe ser un número válido.',
            'purchase_price.min' => 'El precio de compra debe ser al menos S/ 0.01.',
            'purchase_price.max' => 'El precio de compra no puede exceder S/ 1,000,000.',

            'sale_price.required' => 'El precio de venta es obligatorio.',
            'sale_price.numeric' => 'El precio de venta debe ser un número válido.',
            'sale_price.min' => 'El precio de venta debe ser al menos S/ 0.01.',
            'sale_price.max' => 'El precio de venta no puede exceder S/ 1,000,000.',
        ];
    }
}
