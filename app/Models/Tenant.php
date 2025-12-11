<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'company',
        'domain',
        'user_id',
        'chatbot_prompt',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'tenant_users');
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function agents()
    {
        return $this->hasMany(TenantAgent::class);
    }
    public function chatbot()
{
    return $this->hasOne(Chatbot::class);
}
}
