<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Laravel\Cashier\Exceptions\IncompletePayment;
use App\Models\User;
use Illuminate\Support\Str;  

class BillingController extends Controller
{
    public function plans()
    {
        return response()->json([
            'success' => true,
            'plans' => Plan::all(),
        ]);
    }

    public function guestSubscribe(Request $request)
        {
         $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'payment_method' => 'required|string',
            'email' => 'required|email',
                ]);

        $plan = Plan::findOrFail($request->plan_id);

         $user = User::firstOrCreate(
        ['email' => $request->email],
        ['first_name' => 'Guest', 'password' => bcrypt(Str::random(16))]
         );

    try {
        $user->createOrGetStripeCustomer();
        $user->addPaymentMethod($request->payment_method);
        $user->updateDefaultPaymentMethod($request->payment_method);

        $priceId = $plan->stripe_monthly_price_id ?? $plan->stripe_yearly_price_id;

        $subscription = $user->newSubscription('default', $priceId)
            ->create($request->payment_method, ['email' => $user->email]);

        if ($plan->setup_fee > 0) {
            $user->invoiceFor('Setup Fee', $plan->setup_fee * 100);
        }


        return response()->json(['success' => true]);
    } catch (IncompletePayment $e) {
        return response()->json([
            'success' => false,
            'requires_action' => true,
            'client_secret' => $e->payment->client_secret,
        ], 402);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
    }
    }

    public function webhook()
    {
        return \Laravel\Cashier\Cashier::stripe()->webhooks()->handleWebhook();
    }
}