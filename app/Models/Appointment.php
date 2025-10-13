<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'lead_id',
        'title',
        'description',
        'notes',
        'status',
        'type',
        'priority',
        'reminder'
    ];
}
