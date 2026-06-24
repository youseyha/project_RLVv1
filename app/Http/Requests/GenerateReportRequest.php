<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateReportRequest extends FormRequest
{
    /**
     * GENERATE REPORT REQUEST VALIDATION
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|uuid|exists:branches,branch_id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'format' => 'nullable|in:pdf,xlsx,csv',
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.required' => 'សូមជ្រើសរើសកាលបរិច្ឆេទចាប់ផ្តើម',
            'date_to.required' => 'សូមជ្រើសរើសកាលបរិច្ឆេទបញ្ចប់',
            'date_to.after_or_equal' => 'កាលបរិច្ឆេទបញ្ចប់ត្រូវតែធំជាងឬស្មើកាលបរិច្ឆេទចាប់ផ្តើម',
            'format.in' => 'ទម្រង់មិនត្រឹមត្រូវ (pdf, xlsx, csv)',
        ];
    }
}