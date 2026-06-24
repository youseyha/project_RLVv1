<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'plan_name' => 'required|string|max:100|unique:subscription_plans,plan_name',
            'description' => 'nullable|string|max:500',
            'monthly_price' => 'required|numeric|min:0',
            'yearly_price' => 'required|numeric|min:0',
            'max_branches' => 'required|integer|min:0',
            'max_users' => 'required|integer|min:0',
            'max_pos_terminals' => 'required|integer|min:0',
            'has_analytics' => 'boolean',
            'has_api_access' => 'boolean',
            'transaction_limit_monthly' => 'required|integer|min:0',
            'is_active' => 'boolean',
            
            // Features array
            'features' => 'nullable|array',
            'features.*.feature_name' => 'required|string|max:100',
            'features.*.feature_code' => 'required|string|max:50|unique:plan_features,feature_code',
            'features.*.is_enabled' => 'boolean',
            'features.*.description' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'plan_name.required' => 'សូមបញ្ចូលឈ្មោះគម្រោង',
            'plan_name.unique' => 'ឈ្មោះគម្រោងនេះមានរួចហើយ',
            'monthly_price.required' => 'សូមបញ្ចូលតម្លៃប្រចាំខែ',
            'yearly_price.required' => 'សូមបញ្ចូលតម្លៃប្រចាំឆ្នាំ',
            'features.*.feature_code.unique' => 'Feature code នេះមានរួចហើយ',
        ];
    }
}