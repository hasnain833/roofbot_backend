<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;
use Carbon\Carbon;


class BillingController extends Controller
{
    /**
     * Get all available plans
     */
    public function plans()
    {
        return response()->json([
            'success' => true,
            'plans' => Plan::all(),
        ]);
    }

    /**
     * Get user's current subscription info
     */
    public function getSubscription(Request $request)
    {
        $user = $request->user();
        if (!$user)
            return response()->json(['message' => 'Unauthenticated'], 401);

        $subscription = $user->subscription('default');
        if (!$subscription)
            return response()->json(['data' => null]);

        $stripeSub = $subscription->asStripeSubscription();

        return response()->json([
            'data' => [
                'plan_id' => $user->plan_id,
                'plan_name' => $user->plan?->name,
                'stripe_status' => $subscription->stripe_status,
                'is_active' => $subscription->stripe_status === 'active',
                'real_status' => $subscription->stripe_status,
                'is_canceled' => $subscription->canceled(),
                'is_on_grace_period' => $subscription->onGracePeriod(),
                'current_period_end' => isset($stripeSub->current_period_end)
                    ? Carbon::createFromTimestamp($stripeSub->current_period_end)->format('Y-m-d H:i:s')
                    : null,
                'trial_ends_at' => $subscription->trial_ends_at
                    ? Carbon::parse($subscription->trial_ends_at)->format('Y-m-d H:i:s')
                    : null,
                'is_on_trial' => $subscription->onTrial(),
                'ends_at' => $subscription->ends_at
                    ? Carbon::parse($subscription->ends_at)->format('Y-m-d H:i:s')
                    : null,

            ],
        ]);
    }


    /**
     * Create Stripe Checkout Session using Cashier
     */
    public function checkout(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $plan = Plan::findOrFail($request->plan_id);
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $isMonthly = !empty($plan->stripe_monthly_price_id);
            $priceId = $isMonthly ? $plan->stripe_monthly_price_id : $plan->stripe_yearly_price_id;

            $trialDays = 30;

            $lineItems = [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ]
            ];



            $checkout = $user->checkout($lineItems, [
                'success_url' => env('FRONTEND_URL') . '/signin?paid=1',
                'cancel_url' => env('FRONTEND_URL') . '/signup?cancel=1',
                'mode' => 'subscription',
                'subscription_data' => [
                    'trial_period_days' => $trialDays,
                    'metadata' => [
                        'should_add_setup_fee' =>
                            ($plan->slug === 'starter' && $isMonthly) ? 'yes' : 'no',
                        'plan_id' => $plan->id,
                        'user_id' => $user->id,
                    ],
                ],
            ]);

            return response()->json(['url' => $checkout->url]);

        } catch (\Exception $e) {
            \Log::error('Checkout creation failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create checkout session'], 500);
        }
    }



    /**
     * New subscription for user (if not using checkout)
     * This creates subscription via Cashier's newSubscription method
     */
    public function createSubscription(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $plan = Plan::findOrFail($request->plan_id);
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;


            $subscription = $user->newSubscription('default', $priceId)
                ->trialDays(30)  
                ->create();

            $user->plan_id = $plan->id;
            $stripeSub = $subscription->asStripeSubscription();
            $user->current_period_end = isset($stripeSub->current_period_end)
                ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                : null;
            $user->subscription_status = $subscription->stripe_status;
            $user->save();

            Log::info('Subscription created ', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'stripe_id' => $subscription->stripe_id,
                'trial_ends_at' => $subscription->trial_ends_at,
            ]);

            return response()->json([
                'message' => 'Subscription created successfully with trial',
                'subscription' => [
                    'id' => $subscription->stripe_id,
                    'status' => $subscription->stripe_status,
                    'trial_ends_at' => $subscription->trial_ends_at,
                    'plan' => $plan->name,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Subscription creation failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create subscription'], 500);
        }
    }


    /**
     * Cancel user's subscription
     * Cashier handles the actual cancellation with Stripe
     */
    public function cancelSubscription(Request $request)
    {
        $user = $request->user();

        try {
            $subscription = $user->subscription('default');

            if (!$subscription) {
                return response()->json(['message' => 'No active subscription found'], 404);
            }

            // Cancel at end of period (graceful cancellation)
            $subscription->cancel();

            return response()->json([
                'message' => 'Subscription will be canceled at period end',
                'ends_at' => $subscription->ends_at,
                'stripe_status' => $subscription->stripe_status,
            ]);

        } catch (\Exception $e) {
            Log::error('Subscription cancellation failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to cancel subscription'], 500);
        }
    }

    /**
     * Immediately cancel subscription (don't wait for period end)
     */
    public function cancelSubscriptionImmediately(Request $request)
    {
        $user = $request->user();

        try {
            $subscription = $user->subscription('default');

            if (!$subscription) {
                return response()->json(['message' => 'No active subscription found'], 404);
            }

            // Cancel immediately
            $subscription->cancel();

            return response()->json([
                'message' => 'Subscription canceled immediately',
                'stripe_status' => $subscription->stripe_status,
            ]);

        } catch (\Exception $e) {
            Log::error('Immediate cancellation failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to cancel subscription'], 500);
        }
    }

    /**
     * Upgrade/swap to a different plan
     * Cashier automatically prorates charges
     */
    public function upgradeSubscription(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $plan = Plan::findOrFail($request->plan_id);
        $user = $request->user();

        try {
            $subscription = $user->subscription('default');

            if (!$subscription) {
                return response()->json(['message' => 'No active subscription to upgrade'], 404);
            }

            $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;

            // Swap the subscription price (Cashier handles prorating automatically)
            $subscription->swap($priceId);

            // Update user's plan info
            $user->plan_id = $plan->id;
            $user->save();

            Log::info('Subscription upgraded', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'stripe_price' => $priceId,
            ]);

            return response()->json([
                'message' => 'Plan upgraded successfully',
                'plan_name' => $plan->name,
            ]);

        } catch (\Exception $e) {
            Log::error('Upgrade failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to upgrade plan'], 500);
        }
    }

    /**
     * Downgrade to a cheaper plan
     */
    public function downgradeSubscription(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $plan = Plan::findOrFail($request->plan_id);
        $user = $request->user();

        try {
            $subscription = $user->subscription('default');

            if (!$subscription) {
                return response()->json(['message' => 'No active subscription to downgrade'], 404);
            }

            $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;

            // Swap and cancel at period end if downgrading
            $subscription->swap($priceId, [
                'billing_cycle_anchor' => 'now',
            ]);

            $user->plan_id = $plan->id;
            $user->save();

            Log::info('Subscription downgraded', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
            ]);

            return response()->json([
                'message' => 'Plan downgraded successfully',
                'plan_name' => $plan->name,
            ]);

        } catch (\Exception $e) {
            Log::error('Downgrade failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to downgrade plan'], 500);
        }
    }

    /**
     * Resume a canceled subscription
     */
    public function resumeSubscription(Request $request)
    {
        $user = $request->user();

        try {
            $subscription = $user->subscription('default');

            if (!$subscription) {
                return response()->json(['message' => 'No subscription found'], 404);
            }

            if (!$subscription->canceled()) {
                return response()->json(['message' => 'Subscription is not canceled']);
            }

            // Resume the subscription
            $subscription->resume();

            return response()->json([
                'message' => 'Subscription resumed',
                'stripe_status' => $subscription->stripe_status,
            ]);

        } catch (\Exception $e) {
            Log::error('Resume failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to resume subscription'], 500);
        }
    }

    /**
     * Stripe webhook handling
     * Cashier automatically handles most webhook events
     * This is just for logging/custom logic
     */
    // public function handleWebhook(Request $request)
    // {
    //     // Cashier's middleware handles the webhook verification and processing
    //     // This method is called AFTER Cashier processes the webhook

    //     $payload = json_decode($request->getContent(), true);

    //     Log::info('Webhook received and processed by Cashier', [
    //         'type' => $payload['type'] ?? null,
    //         'event_id' => $payload['id'] ?? null,
    //     ]);

    //     // You can add custom logic here based on event type
    //     if (isset($payload['type'])) {
    //         match ($payload['type']) {
    //             'checkout.session.completed' => $this->onCheckoutSessionCompleted($payload),
    //             'customer.subscription.updated' => $this->onSubscriptionUpdated($payload),
    //             'customer.subscription.deleted' => $this->onSubscriptionDeleted($payload),
    //             default => null,
    //         };
    //     }

    //     return response('Webhook handled');
    // }

    /**
     * Custom logic when checkout is completed
     */
    private function onCheckoutSessionCompleted($payload)
    {
        $session = $payload['data']['object'];

        Log::info('Checkout session completed', [
            'session_id' => $session['id'],
            'customer_email' => $session['customer_details']['email'] ?? null,
        ]);

        // Cashier already updated the subscription in the database
        // Add any custom logic here (e.g., send email, create record, etc.)
    }

    /**
     * Custom logic when subscription is updated
     */
    private function onSubscriptionUpdated($payload)
    {
        $sub = $payload['data']['object'];

        Log::info('Subscription updated', [
            'stripe_id' => $sub['id'],
            'status' => $sub['status'],
        ]);

        // Find user and update plan_id if needed
        $user = User::where('stripe_id', $sub['customer'])->first();
        if ($user && $sub['items']['data'][0] ?? null) {
            $priceId = $sub['items']['data'][0]['price']['id'];
            $plan = Plan::where('stripe_monthly_price_id', $priceId)
                ->orWhere('stripe_yearly_price_id', $priceId)
                ->first();

            if ($plan) {
                $user->plan_id = $plan->id;
                $user->save();
            }
        }
    }

    /**
     * Custom logic when subscription is deleted
     */
    private function onSubscriptionDeleted($payload)
    {
        $sub = $payload['data']['object'];

        Log::info('Subscription deleted', [
            'stripe_id' => $sub['id'],
        ]);

        // Cashier already marked subscription as canceled
        // Add any custom logic here
    }
}