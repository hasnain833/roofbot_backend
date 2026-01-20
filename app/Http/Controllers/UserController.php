<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Http\Resources\UserResource;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Auth;
use App\Helper;

class UserController extends Controller
{
    public function __construct()
    {
        UserResource::withoutWrapping();
    }
    /**
     * List all users (only admin)
     */
    public function index(Request $request)
    {
        $query = User::query();
        $query->whereHas('tenantUser', function ($query) {
            $query->where('tenant_id', Auth::user()->tenant->id);
        });
        $query->where('role', '!=', 'superadmin');

        // Sorting
        if ($request->has('sortBy') && $request->has('order')) {
            $query->orderBy($request->sortBy, $request->order);
        }

        // Searching
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        // Pagination
        $page = $request->get('page', 1);
        $pageSize = $request->get('pageSize', 10);

        $total = $query->count();
        $data  = $query->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        return response()->json([
            'data' => $data,
            'total' => $total,
        ]);
    }

    /**
     * Store new user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(['admin', 'user'])],
        ]);
        $authUser = Auth::user();
                 $plan = $request->plan ?? 1;

               $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'plan_id' => $authUser->plan_id,
                'subscription_status' => $authUser->subscription_status,
                'current_period_end' => $authUser->current_period_end,
                'stripe_id' => $authUser->stripe_id,
]);

        $tenant_id = null;
        if (Auth::user()->role != 'superadmin') {
            $tenant_id = Auth::user()->tenantUser->tenant_id;
        } else {
            $tenant_id = Auth::user()->tenant->id;
        }

        TenantUser::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant_id
        ]);

        return response()->json([
            'message' => 'User created successfully',
             'user' => $user
            ], 201);
    }

    /**
     * Show single user
     */
    public function show($id)
    {
        $user = User::findOrFail($id);
        return response()->json($user, 200);
    }

    /**
     * Update user
     */
public function update(Request $request, $id)
{
    $user = User::findOrFail($id);

    $validated = $request->validate([
        'first_name' => 'required|string|max:100',
        'last_name'  => 'required|string|max:100',
        'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        'role' => ['required', Rule::in(['admin', 'user'])],
    ]);

    $user->update($validated);

    return response()->json([
        'message' => 'User updated successfully',
        'user' => $user
    ], 200);
}


    /**
     * Delete user
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully'], 200);
    }
 public function updatePassword(Request $request, $id)
{
    $validated = $request->validate([
        'password' => 'required|string|min:6|confirmed',
    ]);

    $user = User::findOrFail($id);
    $user->password = Hash::make($validated['password']);
    $user->save();

    return response()->json(['message' => 'Password updated successfully'], 200);
}


public function updateProfile(Request $request)
{
    $validated = $request->validate([
        'first_name' => 'required|string|max:100',
        'last_name'  => 'required|string|max:100',
        'email' => ['required', 'email', Rule::unique('users')->ignore(Auth::user()->id)],
    ]);

    $user = User::findOrFail(Auth::user()->id);

    $user->update($validated);

    return response()->json([
        'message' => 'User updated successfully',
        'user' => new UserResource($user)
    ], 200);
}

public function me()
{
    return new UserResource(Auth::user());
}

public function updatePasswordProfile(Request $request)
{
    $validated = $request->validate([
        'password' => 'required|string|min:6|confirmed',
    ]);

    $user = User::findOrFail(Auth::user()->id);
    $user->password = Hash::make($validated['password']);
    $user->save();

    return response()->json(['message' => 'Password updated successfully', 'user' => $user], 200);
}
public function updateTenantPhone(Request $request)
{
    $request->validate([
        'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{1,14}$/'],
    ]);

    $tenant = Helper::tenant();

    $tenant->update([
        'phone' => $request->phone,
    ]);

    return response()->json([
        'success' => true,
        'phone' => $tenant->phone,
    ]);
}
public function showPhone(Request $request)
{
     $tenant = Helper::tenant(); 

    return response()->json([
        'phone' => $tenant->phone ?? null,
    ]);
}


}
