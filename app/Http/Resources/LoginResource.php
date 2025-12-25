<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Plan;

class LoginResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
{
    $user = $this->resource['user'];
    $subscription = $this->resource['subscription'] ?? null;

    $planId = null;
    if ($subscription && $subscription->stripe_price) {
        $plan = Plan::where('stripe_monthly_price_id', $subscription->stripe_price)
            ->orWhere('stripe_yearly_price_id', $subscription->stripe_price)
            ->first();
        $planId = $plan?->id;
    }

    return [
        'token' => $this->resource['token'],
        'authenticated' => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role,
            'stripe_id' => $user->stripe_id,
            'subscription' => $subscription ? [
                'plan_id' => $planId,
                'status' => $subscription->stripe_status,
                'current_period_end' => $subscription->current_period_end,
            ] : null,
        ],
    ];
}
}
