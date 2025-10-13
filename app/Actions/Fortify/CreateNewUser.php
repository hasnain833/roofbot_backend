<?php

namespace App\Actions\Fortify;

use App\Models\Tenant;
use App\Models\TenantAgent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'role' => 'superadmin',
            'password' => Hash::make($input['password']),
        ]);

        $tenant = Tenant::create([
            'company' => @$input['company'],
            'domain' => @$input['domain'],
            'user_id' => $user->id,
        ]);

        TenantAgent::create([
            'tenant_id' => $tenant->id,
            'name' => 'Chatbot',
            'description' => 'Description for Agent 1',
            'type' => 'n8n',
            'status' => 'active'
        ]);
        return $user;
    }
}
