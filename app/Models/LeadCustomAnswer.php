<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadCustomAnswer extends Model
{
    protected $fillable = [
        'tenant_id',
        'lead_id',
        'question',
        'answer',
    ];
}
