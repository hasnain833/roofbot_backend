<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\Models\Lead;
use App\Models\Appointment;
use App\Jobs\SyncAppointmentToGoogle;
use Illuminate\Support\Facades\Log;

class ChatAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'cache'; 
    protected $provider = 'default';
    protected $temperature = 0.7;
    protected $contextWindowSize = 8000; 
    protected $storeMeta = true;
    protected ?string $sessionId = null;

    protected static $currentTenantId;
    public $tenantId;
    public $apiKey;
    protected ?string $tenantPrompt = null;


   public function __construct(?string $sessionId = null)
{
    parent::__construct('temporary-placeholder-key');

    if ($sessionId) {
        $this->sessionId = $sessionId;
        $this->customSessionId = $sessionId;
    }
}


    public function setTenantContext(string $apiKey, int $tenantId): self
    {
        $this->apiKey = $apiKey;
      $this->tenantId = $tenantId;

        static::$currentTenantId = $tenantId;
        config(['laragent.providers.default.api_key' => $apiKey]);

        return $this;
    }
    public function setTenantPrompt(?string $prompt): self
{
    $this->tenantPrompt = $prompt;
    return $this;
}


    public function instructions(): string
    {
        $leadId = $this->sessionId;
        $lead = null;
        $customerName = 'there';

        if ($leadId && is_numeric($leadId)) {
            $lead = Lead::where('id', (int)$leadId)
                ->where('tenant_id', $this->tenantId)
                ->first();

            if ($lead && $lead->first_name) {
                $customerName = $lead->first_name;
            }
        }

        $tenantPromptSection = $this->tenantPrompt
            ? "\n\nTENANT-SPECIFIC INSTRUCTIONS:\n{$this->tenantPrompt}\n"
            : '';

        return <<<PROMPT
You are a friendly, professional roofing company assistant helping an EXISTING customer via SMS whose call is missed by us.

The customer is named {$customerName} and already has a record in our system.

RULES:
- Greet them warmly and personally (use their name if known)
- NEVER ask for name, phone, email, address, or service type — we already have it
- NEVER try to "create a lead" — they are already in the system
- Your goal: Help them book, reschedule, ask questions, or get info
- If they want to book an appointment, ask for preferred date/time
- Keep responses short, warm, and conversational (1-2 sentences)
- If they mention a date/time, parse it and call book_appointment tool

{$tenantPromptSection}

When ready to book, use the book_appointment tool with the correct lead_id (session ID).
PROMPT;
    }

    public function handleMessage($message): string
    {
        return $this->ask($message);
    }

    #[Tool(description: 'Book an appointment for this existing lead. Use the session ID as lead_id.')]
    public function book_appointment(
        string $lead_id,
        string $title,
        int $service_type_id,
        string $start_time,
        string $end_time,
        string $description = '',
        string $notes = ''
    ): string {
        try {
            if (empty($lead_id) || empty($title) || empty($start_time) || empty($end_time)) {
                return "Error: Missing required appointment information.";
            }

            $appointment = Appointment::create([
                'tenant_id' => $this->tenantId,
                'lead_id' => $lead_id,
                'title' => $title,
                'description' => $description,
                'notes' => $notes,
                'service_type_id' => $service_type_id,
                'start_time' => $start_time,
                'end_time' => $end_time,
            ]);
            try {
            dispatch(new SyncAppointmentToGoogle($appointment));

        } catch (\Exception $e) {
           
        }

            Log::info('Appointment booked via chatbot', [
                'lead_id' => $lead_id,
                'tenant_id' => $this->tenantId,
                'start_time' => $start_time
            ]);

            return 'success';
        } catch (\Exception $e) {
            Log::error('Appointment booking failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            return "Error booking appointment: " . $e->getMessage();
        }
    }
}