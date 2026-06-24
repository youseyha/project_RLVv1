<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdmin();
    }

    public function rules(): array
    {
        $planId = $this->route('plan');

        return [
            'plan_name' => [ 
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('subscription_plans')->ignore($planId, 'plan_id'),
            ],
            'description' => 'nullable|string|max:500',
            'monthly_price' => 'sometimes|required|numeric|min:0',
            'yearly_price' => 'sometimes|required|numeric|min:0',
            'max_branches' => 'sometimes|required|integer|min:0',
            'max_users' => 'sometimes|required|integer|min:0',
            'max_pos_terminals' => 'sometimes|required|integer|min:0',
            'has_analytics' => 'boolean',
            'has_api_access' => 'boolean',
            'transaction_limit_monthly' => 'sometimes|required|integer|min:0',
            'is_active' => 'boolean',
            
            'features' => 'nullable|array',
            'features.*.feature_name' => 'required|string|max:100',
            'features.*.feature_code' => 'required|string|max:50',
            'features.*.is_enabled' => 'boolean',
            'features.*.description' => 'nullable|string|max:500',
        ];
    }
}