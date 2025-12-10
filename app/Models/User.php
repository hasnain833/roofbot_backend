<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Cashier\Billable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, Billable;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'company',
        'plan_id',
        'subscription_status',
        'current_period_end',
        'has_valid_subscription', 
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $appends = ['has_valid_subscription'];

   public function getHasValidSubscriptionAttribute()
{
    // Super admin bypass
    if ($this->email === 'griffinb@invictusconnect.com') {
        return true;
    }

    $subscription = $this->subscription('default');

    if (!$subscription) {
        return false;
    }

    // This is the correct way: use Cashier's methods
    return $subscription->active() || $subscription->onGracePeriod();
}

       public function tenants()
{
    return $this->belongsToMany(Tenant::class, 'tenant_users');
}

public function tenant()
{
    return $this->belongsTo(Tenant::class, 'id', 'user_id');
}

public function tenantUser()
{
    return $this->belongsTo(TenantUser::class, 'id', 'user_id');
}

public function leads()
{
    return $this->hasMany(Lead::class);
}
}
