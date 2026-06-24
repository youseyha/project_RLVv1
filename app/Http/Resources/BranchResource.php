<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch_name,
            'branch_code' => $this->branch_code,
            'address' => $this->address,
            'phone' => $this->phone,
            'manager_name' => $this->manager_name,
            'is_active' => $this->is_active,
             // Manager details
            'manager' => $this->when(
                $this->relationLoaded('manager') && $this->manager,
                function () {
                    return [
                        'user_id' => $this->manager->user_id,
                        'email' => $this->manager->email,
                        'username' => $this->manager->username,
                        'is_active' => $this->manager->is_active,
                    ];
                }
            ),
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
            
            // Relationships
            'users' => UserResource::collection($this->whenLoaded('users')),
            'tenant' => [
                'tenant_id' => $this->tenant?->tenant_id,
                'company_name' => $this->tenant?->company_name,
            ] ?? null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}