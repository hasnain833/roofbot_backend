<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileInformationController extends Controller
{
    // Get current logged-in user profile
    public function show()
    {
        return response()->json(Auth::user());
    }

    // Update profile info (name, email, etc.)
    public function update(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name'  => 'sometimes|string|max:255',
            'email'      => 'sometimes|email|unique:users,email,' . $user->id,
            'password'   => 'sometimes|string|min:6|confirmed',
        ]);

        $user->first_name = $request->first_name ?? $user->first_name;
        $user->last_name  = $request->last_name ?? $user->last_name;
        $user->email      = $request->email ?? $user->email;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }
}
