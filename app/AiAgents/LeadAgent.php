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

use App\Models\Reminder;
use Illuminate\Support\Facades\Http;
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
    protected static $currentSessionId;
    public $tenantId;
    public $apiKey;
    protected ?string $tenantPrompt = null;
    protected array $customQuestions = [];
    protected array $serviceTypes = [];
    protected ?string $currentLeadId = null;
    protected bool $leadCreated = false;
    protected int $customAnsweredCount = 0;
    protected static ?string $cachedVisitorTimezone = null;
    protected string $visitorTimezone = 'UTC'; 

    public function setVisitorTimezone(string $timezone): self
    {
        Log::info('CHATBOT setVisitorTimezone called', [
            'session_id' => $this->customSessionId,
            'new_tz' => $timezone,
            'old_tz' => $this->visitorTimezone
        ]);
        $this->visitorTimezone = $timezone;
        self::$cachedVisitorTimezone = $timezone;
        return $this;
    }

    protected function resolveTimezone(): string
    {
        // 1. Check static property (in-memory persistence for current process)
        if (self::$cachedVisitorTimezone) {
            return self::$cachedVisitorTimezone;
        }

        // 2. Check cache (persistent across requests/sessions)
        if ($this->customSessionId) {
            $cached = \Cache::get("agent_tz_{$this->customSessionId}");
            if ($cached) {
                self::$cachedVisitorTimezone = $cached;
                return $cached;
            }
        }

        return $this->visitorTimezone;
    }

    #[Tool(description: 'Set the visitor\'s local timezone ID (IANA format, e.g., America/New_York or Asia/Karachi). Call this as soon as you identify the user\'s location from their address to sync the bot\'s clock to their reality.')]
    public function set_visitor_timezone_context(string $timezone_id): string
    {
        try {
            // Validate the timezone
            $carbon = now()->setTimezone($timezone_id);
            $this->visitorTimezone = $timezone_id;
            
            Log::info('CHATBOT set_visitor_timezone_context tool executed', [
                'session_id' => $this->customSessionId,
                'timezone' => $timezone_id
            ]);

            // Persist the timezone in the cache for this session
            if ($this->customSessionId) {
                \Cache::put("agent_tz_{$this->customSessionId}", $timezone_id, now()->addHours(2));
            }

            return "Context updated: Current visitor time is now " . $carbon->format('g:i A') . " ($timezone_id).";
        } catch (\Exception $e) {
            return "Error setting timezone: " . $e->getMessage();
        }
    }




    public function __construct(?string $sessionId = null)
    {
        parent::__construct(env('OPENAI_API_KEY', 'temporary-placeholder-key'));

        if ($sessionId) {
            $this->customSessionId = $sessionId;
            self::$currentSessionId = $sessionId;
        } elseif (self::$currentSessionId) {
            $this->customSessionId = self::$currentSessionId;
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

        // Restore timezone from cache if available for this session
        if ($this->customSessionId) {
            $cachedTz = \Cache::get("agent_tz_{$this->customSessionId}");
            if ($cachedTz) {
                $this->visitorTimezone = $cachedTz;
            }
        }

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
        $this->serviceTypes = is_array($types) ? $types : $types->toArray();
        return $this;
    }


    public function instructions(): string
    {
        $tz = $this->resolveTimezone();
        $now = now()->setTimezone($tz);
        $currentDate = $now->format('l, F j, Y');
        $currentTime = $now->format('g:i A');
        $currentYear = $now->format('Y');

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
CRITICAL: THE CURRENT YEAR IS {$currentYear}. YOU MUST BOOK ALL APPOINTMENTS FOR THE YEAR {$currentYear}.

TIMEZONE CONTEXT:
- VISITOR TIMEZONE: {$tz}
- CURRENT VISITOR DATE: {$currentDate}
- CURRENT VISITOR TIME: {$currentTime}

DEBUG: Current LeadAgent instance timezone is {$tz}

IMPORTANT: All dates and times provided by the user are relative to their location. 
1. As soon as you gather the user's service address, you MUST identify the correct IANA timezone ID for that location (e.g., America/New_York, Europe/Paris, Africa/Cairo, Asia/Tokyo).
2. IMMEDIATELY call `set_visitor_timezone_context` with that ID to sync your clock to the visitor's reality.
3. When calling `book_appointment`, pass the user's local time exactly as a naive string (YYYY-MM-DD HH:mm:ss). DO NOT perform any UTC conversion yourself.

Example Flow:
- User says "I'm in Chicago" -> You call set_visitor_timezone_context('America/Chicago')
- User says "4pm tomorrow" -> You calculate the date for tomorrow based on the CURRENT VISITOR DATE and call book_appointment with that date.

You are a friendly roofing intake assistant. Your job is to collect customer information for booking appointments.

IMPORTANT: 
- NEVER ask for information the user has already provided.
- Only ask for the next missing piece of information.

DATA TO COLLECT (in this order):
1. Full Name (first and last)
2. Phone Number (You MUST ask the user to provide their REAL phone number. Do NOT guess or populate with a dummy number. Example: 923XXXXXXXXX)
3. Email Address
4. Service Type (must be one of the following):
{$serviceTypeText}
5. Complete Address (street address, city, state, zip code, country)
6. Preferred Appointment Date & Time (Ask NATURALLY, NEVER ask for a specific format like MM/DD/YY)



WORKFLOW:
═══════════════════════════════════════════════════════════════

PHASE 1: COLLECT BASIC INFORMATION
───────────────────────────────────
• Greet the customer and ask for their full name (if not already provided)
• Once you have name, ask for phone number. Explicitly provide an example format (e.g., country code + number).
• Once you have phone, ask for email
• Once you have email, ask what service they need. You MUST list available services vertically.
• Ask the user to provide the exact name of the service they want.
• The service must match ONE of the tenant's service types exactly
• Once you have service, ask for complete address (address, street, city, state, zip, country)
• Once address is received, call the create_lead tool IMMEDIATELY. 
• After lead is created, you MUST first ask all custom questions (Phase 3) before asking for appointment date/time.
• After lead is created and ALL custom questions are answered, ask for the appointment date and time.
• SILENT EXECUTION: When you call create_lead, book_appointment, or save_custom_answer, do NOT tell the user you are doing so.
• Do NOT say "Hold on", "I'll now create...", or "Wait a second".
• Simply execute the tool and respond with the NEXT question or the completion message immediately.
• NEVER repeat already provided information. Review the conversation summary.

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
After the lead is created and custom questions are answered, ask the customer:
'What date and time work best for your appointment?'
(NEVER ask for a specific format like MM/DD/YY).

When user provides the date/time:
1. Parse the date and time from their response
2. Call book_appointment tool with start_time (YYYY-MM-DD HH:mm:ss) and end_time (YYYY-MM-DD HH:mm:ss, usually 1 hour later).

PHASE 5: CONFIRMATION & BOOKING
────────────────────────────────
After booking, respond with a friendly confirmation.
After appointment is booked, clear all data for the next customer.

• The service_type MUST be exactly one of the tenant's service names
• CRITICAL: Before collecting appointment time, you MUST have called `set_visitor_timezone_context` to sync your clock to the user's location.
• Do NOT invent new services
• Do NOT rephrase service names
• If user mentions something similar, choose the closest matching service
• If no match exists, ask the user to choose from the list


✓ SILENCE RULE: Do NOT narrate or announce your internal actions (e.g., "I'm creating your lead"). 
✓ NEVER show tool calls, JSON, or parameters to the user.
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

        $tz = $this->resolveTimezone();
        $now = now()->setTimezone($tz);
        $currentYear = $now->format('Y');

        $basePrompt = <<<PROMPT
CURRENT VISITOR DATE ({$tz}): {$now->format('l, F j, Y')}
CURRENT LOCAL TIME ({$tz}): {$now->format('g:i A')}
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
        $input = trim($input);
        if (empty($input) || empty($options)) {
            return $options[0] ?? 'General';
        }

        // 1. Normalize helper: lowercase and remove non-alphanumeric
        $normalize = function ($str) {
            $str = strtolower($str);
            // Replace various hyphens/dashes with space, then remove special chars
            $str = str_replace(['-', '–', '—', '‑'], ' ', $str);
            return preg_replace('/[^a-z0-9\s]/', '', $str);
        };

        $normalizedInput = $normalize($input);

        // 3. Exact normalized match
        foreach ($options as $option) {
            if ($normalize($option) === $normalizedInput) {
                return $option;
            }
        }

        // 4. Partial normalized match (priority to starts with)
        foreach ($options as $option) {
            $normalizedOption = $normalize($option);
            if (str_starts_with($normalizedOption, $normalizedInput) || str_starts_with($normalizedInput, $normalizedOption)) {
                return $option;
            }
        }
        
        foreach ($options as $option) {
            if (stripos($normalize($option), $normalizedInput) !== false) {
                return $option;
            }
        }

        // 5. Levenshtein Fuzzy Match as last resort
        $bestMatch = $options[0];
        $shortest = -1;

        foreach ($options as $option) {
            $lev = levenshtein($normalizedInput, $normalize($option));
            if ($lev === 0) {
                return $option;
            }
            if ($lev <= $shortest || $shortest < 0) {
                $bestMatch = $option;
                $shortest = $lev;
            }
        }

        // If even the best match is too far (e.g., completely different topic), 
        // we might want to default, but here we'll take the best fuzzy match found.
        return $bestMatch;
    }
private function createFollowups(Lead $lead)
{
    $lead->followups()->delete();

    $status = $lead->status ?? 'New';
    if (!in_array($status, ['New', 'Contacted', 'Proposal Sent'])) {
        return;
    }

    $followupDays = [1, 3, 5];

    foreach ($followupDays as $attempt => $days) {
        \App\Models\Followup::create([
            'lead_id' => $lead->id,
            'followup_date' => now()->addDays($days),
            'note' => "Followup attempt " . ($attempt + 1) . " for status {$status}",
            'type' => $status,
            'done' => false,
        ]);
    }
}
private function createReminder(Appointment $appointment)
{
    $appointment->reminders()->delete();

    Reminder::create([
        'lead_id' => $appointment->lead_id,
        'appointment_id' => $appointment->id,
        'reminder_date' => Carbon::parse($appointment->start_time)->subHours(24),
        'type' => 'appointment',
        'done' => false,
    ]);
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
            Log::info('CHATBOT create_lead tool called', [
                'tenant_id' => $this->tenantId,
                'name' => "$first_name $last_name",
                'service' => $service_type_name,
                'country' => $country
            ]);
            $phone = $this->normalizePhone($phone);
            if (
                empty($first_name) || empty($last_name) || empty($phone) || empty($email) ||
                empty($address) || empty($city) || empty($state) || empty($zip) || empty($country)
            ) {
                return "Error: Missing required information. Please provide all fields.";
            }
            if ($this->leadCreated && $this->currentLeadId) {
                return $this->currentLeadId;
            }

            if (empty($this->serviceTypes)) {
                $this->serviceTypes = \App\Models\ServiceType::where('tenant_id', $this->tenantId)
                    ->select('id', 'name')
                    ->get()
                    ->toArray();
            }

            $serviceNames = collect($this->serviceTypes)->pluck('name')->toArray();
            $service_type_name = $this->closestMatch($service_type_name, $serviceNames);

            $matchedService = collect($this->serviceTypes)->first(function ($s) use ($service_type_name) {
                return $s['name'] === $service_type_name;
            });

            $service_type_id = $matchedService ? (is_array($matchedService) ? $matchedService['id'] : $matchedService->id) : null;

            if (!$service_type_id && !empty($this->serviceTypes)) {
                $first = $this->serviceTypes[0];
                $service_type_id = is_array($first) ? $first['id'] : $first->id;
                $service_type_name = is_array($first) ? $first['name'] : $first->name;
            }




            $lead = Lead::create([
                'tenant_id' => $this->tenantId,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'email' => $email,
                'service_type_name' => $service_type_name,
                'service_type_id' => $service_type_id,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'zip' => $zip,
                'country' => $country,
                'status' => $Status,
            ]);

            $this->createFollowups($lead);


            if (!empty($lead->phone)) {
                $tenant_agent = \App\Models\TenantAgent::where('tenant_id', $this->tenantId)->first();
                $integration = \App\Models\TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
                    ->where('provider', 'twilio')
                    ->first();

                if ($integration) {
                    try {
                        $client = new Client($integration->key, $integration->secret, null, null);
                        
                        $numbers = $client->incomingPhoneNumbers->read();
                        $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');
                        $template = \App\Models\TenantSmsTemplate::where('tenant_id', $this->tenantId)
                            ->where('type', 'lead')
                            ->first();

                        $body = $template ? $template->message : "Hi {first_name}, thank you for your interest in {service_type} at {company_name}. If you have questions, call us at {company_phone}!";

                        // Standard variables for replacement
                        $companyName = $tenant_agent->tenant->company ?? 'Our Company';
                        $companyPhone = $fromNumber;
                        $companyDomain = $tenant_agent->tenant->domain ?? '';
                        $firstName = $lead->first_name;
                        $serviceType = $lead->service_type_name ?? 'our services';

                        $body = str_replace('{first_name}', $firstName, $body);
                        $body = str_replace('{last_name}', $lead->last_name ?? '', $body);
                        $body = str_replace('{service_type}', $serviceType, $body);
                        $body = str_replace('{company_name}', $companyName, $body);
                        $body = str_replace('{company_phone}', $companyPhone, $body);
                        $body = str_replace('{company_phone_number}', $companyPhone, $body);
                        $body = str_replace('{company_domain}', $companyDomain, $body);


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
                    }
                }
            }
            // ================== SEND LEAD EMAIL ==================
            Log::info('CHATBOT attempting lead email', ['email' => $lead->email]);
            if (!empty($lead->email)) {
    $tenant_agent = \App\Models\TenantAgent::where('tenant_id', $this->tenantId)->first();

    $sendgridIntegration = \App\Models\TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
        ->where('provider', 'sendgrid')
        ->first();

    if ($sendgridIntegration) {
        \Log::info('CHATBOT found SendGrid integration', ['lead_id' => $lead->id]);
        try {
            $template = \App\Models\TenantEmailTemplate::where('tenant_id', $this->tenantId)
                ->where('type', 'lead')
                ->first();

            $defaultSubject = 'Thanks for contacting us';
            $defaultBody = "Hi {first_name},\n\nThank you for your interest in {service_type}. Our team will contact you shortly.";

            $subject = $template?->subject ?? $defaultSubject;
            $body = $template?->message ?? $defaultBody;

            // NEW: Variables for company info
            $companyName = $tenant_agent->tenant->company ?? 'Our Company';
            $companyPhone = $tenant_agent->tenant->phone ?? '';
            $companyDomain = $tenant_agent->tenant->domain ?? '';

            // Variables for replacement
            $firstName = $lead->first_name;
            $serviceType = $lead->service_type_name ?? 'our services';

            // Replacement in body
            $body = str_replace('{first_name}', $firstName, $body);
            $body = str_replace('{service_type}', $serviceType, $body);
            $body = str_replace('{company_name}', $companyName, $body);
            $body = str_replace('{company_phone_number}', $companyPhone, $body);
            $body = str_replace('{company_phone}', $companyPhone, $body);
            $body = str_replace('{company_domain}', $companyDomain, $body);

            // Replacement in subject
            $subject = str_replace('{first_name}', $firstName, $subject);
            $subject = str_replace('{service_type}', $serviceType, $subject);
            $subject = str_replace('{company_name}', $companyName, $subject);
            $subject = str_replace('{company_phone_number}', $companyPhone, $subject);
            $subject = str_replace('{company_phone}', $companyPhone, $subject);

            $htmlContent = view('emails.layout', [
                'subject' => $subject,
                'body' => $body,
                'company_name' => $companyName,
                'company_domain' => $companyDomain,
                'company_phone' => $companyPhone
            ])->render();

            $fromEmail = $sendgridIntegration->from_email ?? 'no-reply@yourcompany.com';

            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $sendgridIntegration->key])
                ->post('https://api.sendgrid.com/v3/mail/send', [
                    'personalizations' => [
                        [
                            'to' => [['email' => $lead->email, 'name' => trim($lead->first_name . ' ' . $lead->last_name)]],
                            'subject' => $subject,
                        ]
                    ],
                    'from' => [
                        'email' => $fromEmail, 
                        'name' => $tenant_agent->tenant->company ?? 'Your Company'
                    ],
                    'content' => [
                        ['type' => 'text/html', 'value' => $htmlContent]
                    ],
                    'tracking_settings' => [
                        'click_tracking' => ['enable' => false, 'enable_text' => false],
                        'open_tracking'  => ['enable' => false]
                    ]
                ]);

            if ($response->successful()) {
                \Log::info('Lead email sent via chatbot', [
                    'lead_id' => $lead->id,
                    'email' => $lead->email,
                    'status_code' => $response->status()
                ]);
            } else {
                \Log::warning('Lead email failed via chatbot', [
                    'lead_id' => $lead->id,
                    'status_code' => $response->status(),
                    'response' => $response->body()
                ]);
            }


        } catch (\Exception $e) {
            \Log::error('Chatbot lead email failed', [
                'error' => $e->getMessage(),
                'lead_id' => $lead->id
            ]);
        }
    } else {
        \Log::warning('CHATBOT SendGrid integration NOT FOUND for tenant agent', [
            'tenant_id' => $this->tenantId,
            'agent_id' => $tenant_agent->id
        ]);
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

            if (empty($this->serviceTypes)) {
                $this->serviceTypes = \App\Models\ServiceType::where('tenant_id', $this->tenantId)
                    ->select('id', 'name')
                    ->get()
                    ->toArray();
            }

            $serviceNames = collect($this->serviceTypes)->pluck('name')->toArray();
            $service_type = $this->closestMatch($service_type, $serviceNames);

            $tz = $this->resolveTimezone();
            Log::info('CHATBOT book_appointment raw input', [
                'start_time' => $start_time,
                'end_time' => $end_time,
                'visitor_tz' => $tz
            ]);

            $startCarbon = Carbon::parse($start_time);
            $endCarbon = Carbon::parse($end_time);
            if (!preg_match('/[Z+-]/', substr($start_time, -10))) {
                $startCarbon->shiftTimezone($tz);
            } else {
                $startCarbon->setTimezone($tz);
            }

            if (!preg_match('/[Z+-]/', substr($end_time, -10))) {
                $endCarbon->shiftTimezone($tz);
            } else {
                $endCarbon->setTimezone($tz);
            }

            // Ensure the year is current if not specified or clearly in the past
            if ($startCarbon->year < now()->year) {
                $startCarbon->year = now()->year;
            }
            if ($endCarbon->year < now()->year) {
                $endCarbon->year = now()->year;
            }

            $start_time = $startCarbon->setTimezone('UTC')->toIso8601String();
            $end_time = $endCarbon->setTimezone('UTC')->toIso8601String();

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
            $this->createReminder($appointment);

            $lead = Lead::find($lead_id);

            $tenantAgent = \App\Models\TenantAgent::where('tenant_id', $this->tenantId)->first();

            if ($lead && $lead->phone) {
                $twilioIntegration = \App\Models\TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
                    ->where('provider', 'twilio')
                    ->first();

                if ($twilioIntegration) {
                    $toPhone = $this->normalizePhone($lead->phone);
                    $client = new Client($twilioIntegration->key, $twilioIntegration->secret, null, null);
                    
                    $numbers = $client->incomingPhoneNumbers->read();
                    $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');
                    $this->twilioFromNumber = $fromNumber;

                    $template = \App\Models\TenantSmsTemplate::where('tenant_id', $this->tenantId)
                        ->where('type', 'appointment')
                        ->first();

                    $body = $template
                        ? $template->message
                        : "Hi {first_name}, your appointment for {service_type} with {company_name} is confirmed for {date_time}. Questions? Call {company_phone}.";

                    // Standard variables for replacement
                    $companyName = $tenantAgent->tenant->company ?? 'Our Company';
                    $companyPhone = $fromNumber;
                    $companyDomain = $tenantAgent->tenant->domain ?? '';
                    $firstName = $lead->first_name;
                    $serviceTypeName = $service_type ?? 'our services';
                    $dateTimeFormatted = Carbon::parse($start_time)->setTimezone($this->resolveTimezone())->format('M d, Y h:i A');

                    $body = str_replace('{first_name}', $firstName, $body);
                    $body = str_replace('{last_name}', $lead->last_name ?? '', $body);
                    $body = str_replace('{service_type}', $serviceTypeName, $body);
                    $body = str_replace('{date_time}', $dateTimeFormatted, $body);
                    $body = str_replace('{appointment_title}', $title, $body);
                    $body = str_replace('{company_name}', $companyName, $body);
                    $body = str_replace('{company_phone}', $companyPhone, $body);
                    $body = str_replace('{company_phone_number}', $companyPhone, $body);
                    $body = str_replace('{company_domain}', $companyDomain, $body);

                    try {
                        $statusCallbackUrl = env('APP_URL') . '/api/twilio/status';
                        $client->messages->create($toPhone, [
                            'from' => $fromNumber,
                            'body' => $body,
                            'statusCallback' => $statusCallbackUrl,
                        ]);
                    } catch (\Exception $smsEx) {
                        Log::warning('Chatbot appointment SMS failed', [
                            'error' => $smsEx->getMessage(),
                            'lead_id' => $lead->id
                        ]);
                    }

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
                \Log::info('CHATBOT found SendGrid integration for appointment', ['lead_id' => $lead->id]);
                try {
                    $template = \App\Models\TenantEmailTemplate::where('tenant_id', $this->tenantId)
                        ->where('type', 'appointment')
                        ->first();

                    $defaultSubject = 'Appointment Confirmed: {company_name}';
                    $defaultBody = "Hi {first_name},\n\nYour appointment for {service_type} with {company_name} is scheduled on {date_time}. See you soon!\n\nQuestions? Call us at {company_phone}.";

                    $subject = $template?->subject ?? $defaultSubject;
                    $body = $template?->message ?? $defaultBody;

                    // Variables for replacement
                    $firstName = $lead->first_name;
                    $fullName = trim($lead->first_name . ' ' . $lead->last_name);
                    $serviceTypeName = $service_type ?? 'our services';
                    // CRITICAL: $start_time is stored in UTC, so we must parse it as UTC first, then convert to visitor timezone
                    $dateTimeFormatted = Carbon::parse($start_time, 'UTC')->setTimezone($this->resolveTimezone())->format('M d, Y h:i A');

                    // NEW: Variables for company info
                    $companyName = $tenantAgent->tenant->company ?? 'Our Company';
                    $companyPhone = $this->twilioFromNumber ?? $tenantAgent->tenant->phone ?? '';
                    $companyDomain = $tenantAgent->tenant->domain ?? '';

                    // Replacement in body
                    $body = str_replace('{first_name}', $firstName, $body);
                    $body = str_replace('{service_type}', $serviceTypeName, $body);
                    $body = str_replace('{date_time}', $dateTimeFormatted, $body);
                    $body = str_replace('{company_name}', $companyName, $body);
                    $body = str_replace('{company_phone}', $companyPhone, $body);
                    $body = str_replace('{company_phone_number}', $companyPhone, $body);
                    $body = str_replace('{company_domain}', $companyDomain, $body);

                    // Replacement in subject
                    $subject = str_replace('{first_name}', $firstName, $subject);
                    $subject = str_replace('{service_type}', $serviceTypeName, $subject);
                    $subject = str_replace('{date_time}', $dateTimeFormatted, $subject);
                    $subject = str_replace('{company_name}', $companyName, $subject);
                    $subject = str_replace('{company_phone_number}', $companyPhone, $subject);
                    $subject = str_replace('{company_phone}', $companyPhone, $subject);

                    $fromEmail = $sendgridIntegration->from_email ?? 'no-reply@yourcrm.com'; 

                    $htmlContent = view('emails.layout', [
                        'subject' => $subject,
                        'body' => $body,
                        'company_name' => $tenantAgent->tenant->company ?? 'Our Company',
                        'company_domain' => $tenantAgent->tenant->domain ?? '',
                        'company_phone' => $tenantAgent->tenant->phone ?? ''
                    ])->render();

                    $response = Http::withHeaders(['Authorization' => 'Bearer ' . $sendgridIntegration->key])
                        ->post('https://api.sendgrid.com/v3/mail/send', [
                            'personalizations' => [
                                [
                                    'to' => [['email' => $lead->email, 'name' => $fullName]],
                                    'subject' => $subject,
                                ]
                            ],
                            'from' => [
                                'email' => $fromEmail, 
                                'name' => $tenantAgent->tenant->company ?? 'Your Company'
                            ],
                            'content' => [
                                ['type' => 'text/html', 'value' => $htmlContent]
                            ],
                            'tracking_settings' => [
                                'click_tracking' => ['enable' => false, 'enable_text' => false],
                                'open_tracking'  => ['enable' => false]
                            ]
                        ]);

                    $statusCode = $response->status();

                    if ($response->successful()) {
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
            } else {
                \Log::warning('CHATBOT SendGrid integration NOT FOUND for appointment email', [
                    'tenant_id' => $this->tenantId,
                    'agent_id' => $tenantAgent->id
                ]);
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

    protected function normalizePhone(?string $phone): ?string
    {
        if (!$phone) return null;
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) >= 10 && !str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }
        return $phone;
    }
}