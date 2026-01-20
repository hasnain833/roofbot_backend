<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoginResource;
use App\Http\Resources\UserResource;
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
            $lastPlanId = $user->plan_id;
            $isOwner = false;

if ($user->email === 'griffinb@invictusconnect.com') {
    $isOwner = true;
}

elseif ($user->plan_id !== null) {

    $now = Carbon::now();
    $currentEnd = $user->current_period_end ? Carbon::parse($user->current_period_end) : null;

 if ($user->subscription_status === 'active') {
    $isOwner = true;
}
elseif ($user->subscription_status === 'trialing') {
    $isOwner = true;
}
elseif ($user->subscription_status === 'canceled' && $currentEnd && $currentEnd->greaterThanOrEqualTo($now)) {
    $isOwner = true;
}

}


        return response()->json([
                'token' => $token,
                'user' => new UserResource($user),
            ]);


        });
    }
    

}
