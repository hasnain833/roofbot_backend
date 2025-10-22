<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chatbot extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'bot_token',
        'iframe_url',
        'settings',
        'status',
    ];

    protected $casts = [
        'settings' => 'array', 
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
