<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'role' => $this->role,
            'plan_id' => $this->plan_id,
            'subscription_status' => $this->subscription_status,
            'current_period_end' => $this->current_period_end,
            'stripe_id' => $this->stripe_id,
            'has_valid_subscription' => $this->has_valid_subscription,
            'is_owner' => $this->is_owner,
            'last_plan_id' => $this->last_plan_id,
            'tenant' => $this->tenant ? [
                'id' => $this->tenant->id,
                'phone' => $this->tenant->phone,
            ] : null,
        ];
    }
}
