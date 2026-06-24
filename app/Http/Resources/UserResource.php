<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'username' => $this->username,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'last_login' => $this->last_login?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            
            // Relationships
            'branch' => [
                'branch_id' => $this->branch?->branch_id,
                'branch_name' => $this->branch?->branch_name,
                'branch_code' => $this->branch?->branch_code,
            ],
            
            'tenant' => [
                'tenant_id' => $this->tenant?->tenant_id,
                'company_name' => $this->tenant?->company_name,
            ],
            
            'profile' => [
                'phone_number' => $this->profile?->phone_number,
                'avatar_url' => $this->profile?->avatar_url,
            ],
            
            // Permissions (when loaded)
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->pluck('name');
            }),
            
            'permissions' => $this->when($this->relationLoaded('roles') || $this->relationLoaded('permissions'), function () {
                return $this->getAllPermissions()->pluck('name');
            }),
        ];
    }
}