<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'lead_id',
        'title',
        'description',
        'notes',
        'status',
        'service_type_id',
        'service_type',
        'start_time',
        'end_time',
        'google_event_id',
        'reminder_sent',
        'outlook_event_id',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'reminder_sent' => 'boolean',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
public function serviceType()
{
    return $this->belongsTo(ServiceType::class, 'service_type_id');
}

}

