<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantSmsTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'message',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
