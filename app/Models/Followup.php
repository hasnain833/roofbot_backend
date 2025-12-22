<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Followup extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'followup_date',
        'note',
        'type',
        'done',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
