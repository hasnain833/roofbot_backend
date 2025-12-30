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
use SendGrid\Mail\Mail;
use SendGrid;
use App\Models\Reminder;
use Carbon\Carbon;



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
    protected ?string $currentLeadId = null;
    protected bool $leadCreated = false;
    protected int $customAnsweredCount = 0;



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
• Dont tell users that you created a lead or calling create_lead tool for lead creation.
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

IMPORTANT MEMORY RULE:
─────────────────────
The value returned by create_lead tool
IS the ONLY valid lead_id.
Store it and reuse it for:
• save_custom_answer
• book_appointment

If no lead_id exists:
DO NOT call save_custom_answer
DO NOT call book_appointment

PHASE 3: ASK CUSTOM TENANT QUESTIONS
────────────────────────────────────
{$customQuestionsText}
After the lead is created:
• Ask ALL tenant custom questions one by one
After user answers a question:
- You MUST use the EXACT lead_id returned by the create_lead tool
- NEVER invent or guess lead_id
- Call save_custom_answer tool with:
  • lead_id = the string returned by create_lead
  • question
  • answer

• Do NOT repeat already answered questions
• Do NOT ask appointment date until all custom questions are answered


PHASE 4: COLLECT APPOINTMENT DETAILS (after lead is created and custom questions are asked)
───────────────────────────────────────────────────────────
After the lead is created, ask the customer:
'What date and time work best for your appointment? Please provide in format: MM/DD/YY H:MM AM/PM (for example: 12/25/25 2:00 PM)'

When user provides the date/time:
1. Parse the date and time from their response
2. Convert to ISO 8601 format: YYYY-MM-DDTHH:MM:SSZ
   Example: 12/25/25 2:00 PM → 2025-12-25T14:00:00Z
3. Calculate end time as 1 hour after start time
4. Call book_appointment tool

PHASE 5: CONFIRMATION & BOOKING
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
✓ Call create_lead only once per session
✓ Must create a lead when required information is collected 
✓ When lead is created must ask custom questions {$customQuestionsText}
✓ when lead is created and custom questions are asked ,ask for appointment date and time
✓ Only call book_appointment after user provides appointment date/time
If there exists any tenant custom question
that does NOT have a saved answer:
 DO NOT ask for appointment date
 DO NOT call book_appointment
 Ask the next unanswered custom question

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

INTERNAL MEMORY:
───────────────
Answered custom questions are those
that have already been saved using save_custom_answer tool.
Never ask them again.

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
            if ($this->leadCreated && $this->currentLeadId) {
                return $this->currentLeadId;
            }

            $serviceNames = collect($this->serviceTypes)->pluck('name')->toArray();
            $service_type_name = $this->closestMatch($service_type_name, $serviceNames);

            $serviceType = \App\Models\ServiceType::where('tenant_id', $this->tenantId)
                ->whereRaw('LOWER(name) = ?', [strtolower($service_type_name)])
                ->first();

            if (!$serviceType) {
                $serviceType = \App\Models\ServiceType::where('tenant_id', $this->tenantId)->first();
            }




            $lead = Lead::create([
                'tenant_id' => $this->tenantId,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'email' => $email,
                'service_type_name' => $service_type_name,
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
                        $template = \App\Models\TenantSmsTemplate::where('tenant_id', $this->tenantId)
                            ->where('type', 'lead')
                            ->first();

                        $body = $template ? $template->message : "Hello {first_name}, thank you for showing interest in {service_type} services.";

                        $body = str_replace('{first_name}', $lead->first_name, $body);
                       $body = str_replace('{service_type}', $lead->service_type_name ?? 'our services', $body);


                       $statusCallbackUrl = env('APP_URL') . '/api/twilio/status';

                        $twilioMessage = $client->messages->create($lead->phone, [
                'from' => $fromNumber,
                'body' => $body,
                'statusCallback' => $statusCallbackUrl,
            ]);
                      \App\Models\Message::create([
                'lead_id' => $lead->id,
                'text' => $body,
                'out' => true,
                'status' => $twilioMessage->status,
                'sid' => $twilioMessage->sid,
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
            $this->leadCreated = true;
            $this->currentLeadId = (string) $lead->id;

            return $this->currentLeadId;



        } catch (\Exception $e) {
            Log::error('Lead creation failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);

            return "Error creating lead: " . $e->getMessage();
        }


    }

    #[Tool(description: 'Save answer to a custom tenant question for a lead')]
    public function save_custom_answer(
        string $lead_id,
        string $question,
        string $answer
    ): string {
      $leadId = (int) $lead_id;

if ($leadId <= 0) {
    Log::warning('Invalid lead_id passed to save_custom_answer', [
        'lead_id' => $lead_id,
        'tenant_id' => $this->tenantId,
    ]);
    return 'invalid_lead_id';
}


        try {
            \App\Models\LeadCustomAnswer::updateOrCreate(

                [
                    'lead_id' => $leadId,

                    'question' => $question,
                ],
                [
                    'tenant_id' => $this->tenantId,
                    'answer' => $answer,
                ]
            );
            $this->customAnsweredCount++;

            return 'saved';


        } catch (\Exception $e) {
            Log::error('Custom answer save failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId,
            ]);

            return 'error';
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
            $lead = \App\Models\Lead::find($lead_id);

            if ($lead && $lead->phone) {

                $tenantAgent = \App\Models\TenantAgent::where('tenant_id', $this->tenantId)->first();

                $twilioIntegration = \App\Models\TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
                    ->where('provider', 'twilio')
                    ->first();

                if ($twilioIntegration) {

                    $client = new Client($twilioIntegration->key, $twilioIntegration->secret);
                    $numbers = $client->incomingPhoneNumbers->read();
                    $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');

                    $template = \App\Models\TenantSmsTemplate::where('tenant_id', $this->tenantId)
                        ->where('type', 'appointment')
                        ->first();

                    $body = $template
                        ? $template->message
                        : "Hi {first_name}, your appointment for {service_type} is scheduled on {date_time}.";

                    $body = str_replace('{first_name}', $lead->first_name, $body);
                    $body = str_replace('{service_type}', $service_type, $body);
                    $body = str_replace(
                        '{date_time}',
                        Carbon::parse($start_time)->format('M d, Y h:i A'),
                        $body
                    );

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
                }
            }
            if ($lead->email && $tenantAgent) {
            $sendgridIntegration = \App\Models\TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
                ->where('provider', 'sendgrid')
                ->first();

            if ($sendgridIntegration) {
                try {
                    $template = \App\Models\TenantEmailTemplate::where('tenant_id', $this->tenantId)
                        ->where('type', 'appointment')
                        ->first();

                    $defaultSubject = 'Your Appointment is Confirmed';
                    $defaultBody = "Hi {first_name},\n\nYour appointment for {service_type} is scheduled on {date_time}. See you soon!";

                    $subject = $template?->subject ?? $defaultSubject;
                    $body = $template?->message ?? $defaultBody;

                    $body = str_replace('{first_name}', $lead->first_name, $body);
                    $body = str_replace('{service_type}', $service_type ?? 'our services', $body);
                    $body = str_replace('{date_time}', Carbon::parse($start_time)->format('M d, Y h:i A'), $body);

                    $fromEmail = $sendgridIntegration->from_email ?? 'no-reply@yourcrm.com'; 

                    $email = new Mail();
                    $email->setFrom($fromEmail, $tenantAgent->tenant->company ?? 'Your Company');
                    $email->setSubject($subject);

                    $fullName = trim($lead->first_name . ' ' . ($lead->last_name ?? ''));
                    $email->addTo($lead->email, $fullName);
                    $email->addContent("text/plain", $body);

                    $sendgrid = new SendGrid($sendgridIntegration->key);
                    $response = $sendgrid->send($email);

                    $statusCode = $response?->statusCode();

                    if ($statusCode === 202) {
                        Log::info('Chatbot appointment email sent successfully', [
                            'appointment_id' => $appointment->id,
                            'to' => $lead->email,
                            'from' => $fromEmail,
                        ]);
                    } else {
                        Log::warning('Chatbot appointment email failed (non-202)', [
                            'appointment_id' => $appointment->id,
                            'status_code' => $statusCode,
                            'response' => $response?->body(),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Chatbot appointment email failed: ' . $e->getMessage(), [
                        'appointment_id' => $appointment->id,
                        'to' => $lead->email ?? 'unknown',
                    ]);
                }
            }
        }
            

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