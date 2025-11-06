<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoginResource;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Http\Requests\LoginRequest;
use App\Models\Plan;
use Carbon\Carbon;

class LoginController extends AuthenticatedSessionController
{
    public function store(LoginRequest $request)
    {
        return $this->loginPipeline($request)->then(function ($request) {
            $user = Auth::user();
            $token = $user->createToken('auth_token', ['role:' . $user->role])->plainTextToken;

            $isOwner = false;

            if ($user->email === 'griffinb@invictusconnect.com') {
            $isOwner = true;
                }
            elseif ($user->subscription_status === 'active' && $user->plan_id !== null) {
            $isOwner = true;
            }

        return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'plan_id' => $user->plan_id,
                    'subscription_status' => $user->subscription_status,
                    'current_period_end' => $user->current_period_end
                        ? Carbon::parse($user->current_period_end)->toDateTimeString()
                        : null,
                    'stripe_customer_id' => $user->stripe_customer_id,
                    'has_valid_subscription' => $user->has_valid_subscription, 
                    'is_owner' => $isOwner,
                ],
            ]);


        });
    }
    

}
