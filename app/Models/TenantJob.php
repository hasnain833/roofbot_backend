<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantJob extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'title',
        'description',
        'notes',
        'status',
        'type',
        'priority',
        'reminder'
    ];
}
