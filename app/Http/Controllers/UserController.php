<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Http\Resources\UserResource;

class UserController extends Controller
{
    /**
     * List all users (only admin)
     */
    public function index(Request $request)
    {
        $query = User::query();
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
        $page     = $request->get('page', 1);
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

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'] ?? null,
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'role'       => $validated['role'],
        ]);

        return response()->json(['message' => 'User created successfully', 'user' => $user], 201);
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
        'first_name' => 'sometimes|string|max:100',
        'last_name'  => 'sometimes|string|max:100',
        'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
        'role' => ['sometimes', Rule::in(['admin', 'user'])],
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


}
