<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\StockMovement;

class BulkAdjustmentRequest extends FormRequest
{
    /**
     * ════════════════════════════════════════════════════════════
     * BULK ADJUSTMENT REQUEST VALIDATION
     * ════════════════════════════════════════════════════════════
     * 
     * ពិនិត្យទិន្នន័យសម្រាប់កែតម្រូវស្តុកច្រើន
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'adjustments' => 'required|array|min:1|max:100',
            'adjustments.*.branch_id' => 'required|uuid|exists:branches,branch_id',
            'adjustments.*.product_id' => 'required|uuid|exists:products,product_id',
            'adjustments.*.adjustment_quantity' => 'required|numeric|min:0.01',
            'adjustments.*.movement_type' => 'nullable|in:' . implode(',', [
                StockMovement::TYPE_ADJUSTMENT_IN,
                StockMovement::TYPE_ADJUSTMENT_OUT,
                StockMovement::TYPE_DAMAGE,
                StockMovement::TYPE_RETURN_FROM_CUSTOMER,
                StockMovement::TYPE_RETURN_TO_SUPPLIER,
                StockMovement::TYPE_SALE
            ]),
            'adjustments.*.notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'adjustments.required' => 'សូមបញ្ចូលបញ្ជីកែតម្រូវ',
            'adjustments.min' => 'សូមបញ្ចូលយ៉ាងហោចណាស់ 1 កែតម្រូវ',
            'adjustments.max' => 'អាចកែតម្រូវបានតែ 100 ក្នុងពេលតែមួយ',
            'adjustments.*.branch_id.required' => 'សូមជ្រើសរើសសាខា',
            'adjustments.*.product_id.required' => 'សូមជ្រើសរើសផលិតផល',
            'adjustments.*.adjustment_quantity.required' => 'សូមបញ្ចូលចំនួនកែតម្រូវ',
            'adjustments.*.adjustment_quantity.min' => 'ចំនួនកែតម្រូវត្រូវបានធ្វើឱ្យធំជាង 0.01',
        ];
    }
}