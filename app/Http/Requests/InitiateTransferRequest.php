<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateTransferRequest extends FormRequest
{
    /**
     * ════════════════════════════════════════════════════════════
     * INITIATE TRANSFER REQUEST VALIDATION
     * ════════════════════════════════════════════════════════════
     * 
     * ពិនិត្យទិន្នន័យសម្រាប់ចាប់ផ្តើមការផ្ទេរស្តុក
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_branch_id' => 'required|uuid|exists:branches,branch_id',
            'to_branch_id' => 'required|uuid|exists:branches,branch_id|different:from_branch_id',
            'product_id' => 'required|uuid|exists:products,product_id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'from_branch_id.required' => 'សូមជ្រើសរើសសាខាប្រភព',
            'from_branch_id.exists' => 'សាខាប្រភពមិនត្រឹមត្រូវ',
            'to_branch_id.required' => 'សូមជ្រើសរើសសាខាគោលដៅ',
            'to_branch_id.different' => 'សាខាគោលដៅត្រូវខុសពីសាខាប្រភព',
            'product_id.required' => 'សូមជ្រើសរើសផលិតផល',
            'product_id.exists' => 'ផលិតផលមិនត្រឹមត្រូវ',
            'quantity.required' => 'សូមបញ្ចូលចំនួន',
            'quantity.min' => 'ចំនួនត្រូវតែធំជាង 0',
        ];
    }
}