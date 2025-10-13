<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantAgentIntegration extends Model
{
    protected $fillable = [
        'tenant_agent_id',
        'provider',
        'key',
        'secret',
        'meta'
    ];

    public function agent()
    {
        return $this->belongsTo(TenantAgent::class, 'tenant_agent_id');
    }
}
