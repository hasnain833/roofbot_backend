<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantAgent extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'type',
        'status'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function integrations()
    {
        return $this->hasMany(TenantAgentIntegration::class);
    }
}
