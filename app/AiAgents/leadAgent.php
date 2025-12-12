<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\Models\Lead;
use App\Models\Appointment;
use App\Jobs\SyncAppointmentToGoogle;
use Illuminate\Support\Facades\Log;


class LeadAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'cache'; 
    protected $provider = 'default';
    protected $temperature = 0.7;
    protected $contextWindowSize = 8000; 
    protected $storeMeta = true;
    protected static $currentTenantId;
    public $tenantId;
    public $apiKey;

     public function __construct(?string $sessionId = null)
{
    parent::__construct(env('OPENAI_API_KEY', 'temporary-placeholder-key'));

    if ($sessionId) {
        $this->customSessionId = $sessionId;
    }
    if (static::$currentTenantId !== null) {
            $this->tenantId = static::$currentTenantId;
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
    public function instructions(): string
    {
        return 

"You are a friendly roofing intake assistant. Your job is to collect customer information for booking appointments.

IMPORTANT: 
- NEVER ask for information the user has already provided.
- Only ask for the next missing piece of information.

DATA TO COLLECT (in this order):
1. Full Name (first and last)
2. Phone Number
3. Email Address
4. Service Type (roof inspection, repair, replacement, gutter work, siding, window cleaning, etc.)
5. Complete Address (street address, city, state, zip code, country)
6. Preferred Appointment Date & Time (format: MM/DD/YY H:MM AM/PM)

SERVICE TYPE MAPPING:
- roof / roofing → ID: 1
- gutter → ID: 2
- repair / repairing → ID: 3
- siding → ID: 4
- window / windows / cleaning → ID: 5
- anything else → ID: 6 (Other)

WORKFLOW:
═══════════════════════════════════════════════════════════════

PHASE 1: COLLECT BASIC INFORMATION
───────────────────────────────────
• Greet the customer and ask for their full name (if not already provided)
• Once you have name, ask for phone number
• Once you have phone, ask for email
• Once you have email, ask what service they need
• Once you have service, ask for complete address (street, city, state, zip, country)

DO NOT ASK FOR THE SAME INFORMATION TWICE. Review the conversation summary above.

PHASE 2: CREATE LEAD (when all info is collected)
──────────────────────────────────────────────────
When you have ALL of the following:
✓ first_name
✓ last_name  
✓ phone
✓ email
✓ service_type (with ID)
✓ address (street)
✓ city
✓ state
✓ zip
✓ country

Call the create_lead tool with these exact values.

PHASE 3: COLLECT APPOINTMENT DETAILS (after lead is created)
───────────────────────────────────────────────────────────
After the lead is created, ask the customer:
'What date and time work best for your appointment? Please provide in format: MM/DD/YY H:MM AM/PM (for example: 12/25/25 2:00 PM)'

When user provides the date/time:
1. Parse the date and time from their response
2. Convert to ISO 8601 format: YYYY-MM-DDTHH:MM:SSZ
   Example: 12/25/25 2:00 PM → 2025-12-25T14:00:00Z
3. Calculate end time as 1 hour after start time
4. Call book_appointment tool

PHASE 4: CONFIRMATION & BOOKING
────────────────────────────────
After booking, respond with a friendly confirmation.
After appointment is booked, clear all data for the next customer.

CRITICAL RULES:
───────────────
✓ ALWAYS read the conversation summary above before responding
✓ NEVER repeat questions about information already provided
✓ NEVER show tool calls, JSON, or parameters to the user
✓ Be conversational, warm, and professional
✓ Keep responses brief (1-2 sentences per message)
✓ Only ask for ONE missing piece of information at a time
✓ Before calling create_lead, ensure ALL required fields are ready
✓ Only call book_appointment after user provides appointment date/time

CONVERSATION RULES:
──────────────────
• If user provides their name as 'John Smith':
  - Extract: first_name = 'John', last_name = 'Smith'
  - Do NOT ask again for first or last name
  
• If user says their phone is '+923069171223':
  - Remember it
  - Do NOT ask again
  
• If the user says 'roof inspection':
  - Recognize this as service_type_id = 1, service_type_name = 'Roof Inspection'
  - Do NOT ask what service they need again

• If user provides partial address info:
  - Store what they gave you
  - Ask only for the missing parts (city if you got street, etc.)
";
    }

    public function prompt($message): string
    {
        return $message;
    }

    public function handleMessage($message): string
    {
        return $this->ask($message);
    }

    #[Tool(description: 'Create a new lead in the database. Call this ONLY after collecting: first_name, last_name, phone, email, service_type_id, address, city, state, zip, country. Returns the created lead ID.')]
    public function create_lead(
        string $first_name,
        string $last_name,
        string $phone,
        string $email,
        int $service_type_id,
        string $address,
        string $city,
        string $state,
        string $zip,
        string $country,
        string $Status = 'New'
    ): string {
        try {
            // Validate required fields
            if (empty($first_name) || empty($last_name) || empty($phone) || empty($email) ||
                empty($address) || empty($city) || empty($state) || empty($zip) || empty($country)) {
                return "Error: Missing required information. Please provide all fields.";
            }

            $lead = Lead::create([
                'tenant_id' => $this->tenantId,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'email' => $email,
                'service_type_id' => $service_type_id,
                'address' => $address,
                'state' => $state,
                'city' => $city,
                'zip' => $zip,
                'country' => $country,
                'Status' => $Status,
            ]);

            Log::info('Lead created', [
                'lead_id' => $lead->id,
                'tenant_id' => $this->tenantId,
                'name' => "{$first_name} {$last_name}",
                'phone' => $phone
            ]);

            return (string) $lead->id;
        } catch (\Exception $e) {
            Log::error('Lead creation failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            return "Error creating lead: " . $e->getMessage();
        }
    }

    #[Tool(description: 'Book an appointment for a lead. Call this ONLY after the lead is created and you have appointment date/time. Requires lead_id, title, service_type_id, start_time (ISO 8601), and end_time (ISO 8601).')]
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
            // Validate required fields
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