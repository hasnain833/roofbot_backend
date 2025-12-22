<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'status',
        'service_type_id',
        'service_type_name',
        'ai_chat_summary',
        'missed_call_active',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

  public function serviceType()
{
    return $this->belongsTo(ServiceType::class);
}

    public function followups()
{
    return $this->hasMany(Followup::class);
}

public function reminders()
{
    return $this->hasMany(Reminder::class);
}


}
