<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\StockMovement;

class StockAdjustmentRequest extends FormRequest
{
    /**
     * ════════════════════════════════════════════════════════════
     * STOCK ADJUSTMENT REQUEST VALIDATION
     * ════════════════════════════════════════════════════════════
     * 
     * ពិនិត្យទិន្នន័យសម្រាប់កែតម្រូវស្តុក
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'required|uuid|exists:branches,branch_id',
            'product_id' => 'required|uuid|exists:products,product_id',
            'adjustment_quantity' => 'required|numeric|min:0.01',
            'movement_type' => 'required|in:' . implode(',', [
                StockMovement::TYPE_ADJUSTMENT_IN,
                StockMovement::TYPE_ADJUSTMENT_OUT,
                StockMovement::TYPE_DAMAGE,
                StockMovement::TYPE_SALE,
                StockMovement::TYPE_RETURN_TO_SUPPLIER,
                StockMovement::TYPE_RETURN_FROM_CUSTOMER,
            ]),
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'សូមជ្រើសរើសសាខា',
            'product_id.required' => 'សូមជ្រើសរើសផលិតផល',
            'adjustment_quantity.required' => 'សូមបញ្ចូលចំនួនកែតម្រូវ',
            'adjustment_quantity.min' => 'ចំនួនកែតម្រូវត្រូវបានធ្វើឱ្យធំជាង 0.01',
            'movement_type.required' => 'សូមជ្រើសរើសប្រភេទចលនា',
            'movement_type.in' => 'ប្រភេទចលនាមិនត្រឹមត្រូវ',
        ];
    }

    public function attributes(): array
    {
        return [
            'adjustment_quantity' => 'ចំនួនកែតម្រូវ',
            'movement_type' => 'ប្រភេទចលនា',
        ];
    }
}