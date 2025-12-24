<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'appointment_id',
        'reminder_date',
        'type',
        'done',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
        public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}
