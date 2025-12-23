<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\Models\Lead;
use App\Models\Appointment;
use App\Jobs\SyncAppointmentToGoogle;
use App\Jobs\SyncAppointmentToOutlook;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;


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
    protected ?string $tenantPrompt = null;
    protected array $customQuestions = [];
    protected array $serviceTypes = [];


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
    public function setTenantPrompt(?string $prompt): self
    {
        $this->tenantPrompt = $prompt;
        return $this;
    }
    public function setCustomQuestions(array $questions): self
    {
        $this->customQuestions = $questions;
        return $this;
    }

    public function setServiceTypes($types): self
    {
        $this->serviceTypes = $types->toArray();
        return $this;
    }


    public function instructions(): string
    {
        $tenantPromptSection = $this->tenantPrompt
            ? "\n\nTENANT-SPECIFIC BEHAVIOR:\n────────────────────────\n{$this->tenantPrompt}\n"
            : '';
        $serviceTypeText = collect($this->serviceTypes)
            ->pluck('name')
            ->map(fn($name) => "- {$name}")
            ->implode("\n");


        $customQuestionsText = collect($this->customQuestions)->map(
            fn($q, $i) => ($i + 1) . ". {$q['question']}"
        )->implode("\n");

        return <<<PROMPT
You are a friendly roofing intake assistant. Your job is to collect customer information for booking appointments.

IMPORTANT: 
- NEVER ask for information the user has already provided.
- Only ask for the next missing piece of information.

DATA TO COLLECT (in this order):
1. Full Name (first and last)
2. Phone Number
3. Email Address
4. Service Type (must be one of the following):
{$serviceTypeText}
5. Complete Address (street address, city, state, zip code, country)
6. Preferred Appointment Date & Time (format: MM/DD/YY H:MM AM/PM)



WORKFLOW:
═══════════════════════════════════════════════════════════════

PHASE 1: COLLECT BASIC INFORMATION
───────────────────────────────────
• Greet the customer and ask for their full name (if not already provided)
• Once you have name, ask for phone number
• Once you have phone, ask for email
• Once you have email, ask what service they need from the available services listed
• The service must match ONE of the tenant's service types exactly
• Once you have service, ask for complete address (address, street, city, state, zip, country)
• once address is recieved must call create_lead toll then ask for appointment date and time. 
• Dont tell users that you created a lead.
DO NOT ASK FOR THE SAME INFORMATION TWICE. Review the conversation summary above.

PHASE 2: CREATE LEAD (when all info is collected)
──────────────────────────────────────────────────
When you have ALL of the following:
✓ first_name
✓ last_name  
✓ phone
✓ email
✓ service_type_name
✓ address (street)
✓ city
✓ state
✓ zip
✓ country

Call the create_lead tool with these exact values.you must not call before taking info.
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

SERVICE TYPE RULES:
──────────────────
• The service_type MUST be exactly one of the tenant's service names
• Do NOT invent new services
• Do NOT rephrase service names
• If user mentions something similar, choose the closest matching service
• If no match exists, ask the user to choose from the list


CRITICAL RULES:
───────────────
✓ ALWAYS read the conversation summary above before responding
✓ NEVER repeat questions about information already provided
✓ NEVER show tool calls, JSON, or parameters to the user,dont tell user that you are creating a lead.
✓ Be conversational, warm, and professional
✓ Keep responses brief (1-2 sentences per message)
✓ Only ask for ONE missing piece of information at a time
✓ Before calling create_lead, ensure ALL required fields are ready
✓ Must create a lead when required information is collected 
✓ When lead is created must ask For Yes to book appointment
✓ Only call book_appointment after user provides appointment date/time

CONVERSATION RULES:
──────────────────
• If user provides their name as 'John Smith':
  - Extract: first_name = 'John', last_name = 'Smith'
  - Do NOT ask again for first or last name
  
• If user says their phone 
  - Remember it
  - Do NOT ask again
  

• If user provides partial address info:
  - Store what they gave you
  - Ask only for the missing parts (city if you got street, etc.)

ADDITIONAL TENANT QUESTIONS:
───────────────────────────
{$customQuestionsText}

RULES FOR CUSTOM QUESTIONS:
• Ask ONE question at a time
• Store answers internally
• Do NOT repeat answered questions
{$tenantPromptSection}
PROMPT;
    }

    public function prompt($message): string
    {
        $tenantPrompt = $this->tenantPrompt ?? '';

        $serviceTypesText = collect($this->serviceTypes)
            ->pluck('name')
            ->map(fn($name) => "- {$name}")
            ->implode("\n");

        $customQuestionsText = collect($this->customQuestions)
            ->map(fn($q, $i) => ($i + 1) . ". {$q['question']}")
            ->implode("\n");

        $basePrompt = <<<PROMPT
{$tenantPrompt}

AVAILABLE SERVICE TYPES:
{$serviceTypesText}

CUSTOM QUESTIONS:
{$customQuestionsText}

USER MESSAGE:
{$message}
PROMPT;

        return $basePrompt;
    }


    public function handleMessage($message): string
    {
        $prompt = $this->prompt($message);
        return $this->ask($prompt);
    }
    protected function closestMatch(string $input, array $options): string
    {
        foreach ($options as $option) {
            if (stripos($input, $option) !== false) {
                return $option;
            }
        }
        return $options[0] ?? 'General';
    }



    #[Tool(description: 'Create a new lead in the database. Call this ONLY after collecting: first_name, last_name, phone, email, service_type_name, address, city, state, zip, country. Returns the created lead ID.')]
    public function create_lead(
        string $first_name,
        string $last_name,
        string $phone,
        string $email,
        string $service_type_name,
        string $address,
        string $city,
        string $state,
        string $zip,
        string $country,
        string $Status = 'New'
    ): string {
        try {
            if (
                empty($first_name) || empty($last_name) || empty($phone) || empty($email) ||
                empty($address) || empty($city) || empty($state) || empty($zip) || empty($country)
            ) {
                return "Error: Missing required information. Please provide all fields.";
            }
            $serviceNames = collect($this->serviceTypes)->pluck('name')->toArray();
            $service_type_name = $this->closestMatch($service_type_name, $serviceNames);

            $serviceType = \App\Models\ServiceType::where('tenant_id', $this->tenantId)
                ->where('name', $service_type_name)
                ->first();



            $lead = Lead::create([
                'tenant_id' => $this->tenantId,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'email' => $email,
                'service_type' => $service_type_name,
                'service_type_id' => $serviceType?->id,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'zip' => $zip,
                'country' => $country,
                'status' => $Status,
            ]);

            if (!empty($lead->phone)) {
                $tenant_agent = \App\Models\TenantAgent::where('tenant_id', $this->tenantId)->first();
                $integration = \App\Models\TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
                    ->where('provider', 'twilio')
                    ->first();

                if ($integration) {
                    try {
                        $client = new Client($integration->key, $integration->secret);
                        $numbers = $client->incomingPhoneNumbers->read();
                        $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');
                        $template = \App\Models\TenantSmsTemplate::where('tenant_id', $this->tenantId)->first();
                        $body = $template ? $template->message : "Hello {first_name}, thank you for showing interest in {service_type} services.";

                        $body = str_replace('{first_name}', $lead->first_name, $body);
                        $body = str_replace( '{service_type}',$lead->service_type,$body);




                        $client->messages->create($lead->phone, [
                            'from' => $fromNumber,
                            'body' => $body,
                        ]);

                        \App\Models\Message::create([
                            'lead_id' => $lead->id,
                            'text' => $body,
                            'out' => true,
                            'status' => 'sent',
                        ]);

                    } catch (\Exception $e) {
                        Log::error("Twilio send failed: " . $e->getMessage());
                        return response()->json([
                            'message' => 'Lead created but failed to send SMS',
                            'sms_error' => $e->getMessage(),
                            'data' => $lead
                        ], 200);
                    }
                }
            }

            Log::info('Lead created via chatbot', [
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


    #[Tool(description: 'Book an appointment for a lead. Call this ONLY after the lead is created and you have appointment date/time. Requires lead_id, title, service_type, start_time (ISO 8601), and end_time (ISO 8601).')]
    public function book_appointment(
        string $lead_id,
        string $title,
        string $service_type,
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
                'service_type' => $service_type,
                'start_time' => $start_time,
                'end_time' => $end_time,
            ]);
            try {
                dispatch(new SyncAppointmentToGoogle($appointment));
                dispatch(new SyncAppointmentToOutlook($appointment));

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