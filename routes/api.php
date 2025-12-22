<?php

use App\Http\Controllers\AgentIntegrationController;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\ProfileInformationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ChatbotController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\TwilioController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CrmJobController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\TenantSmsTemplateController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ServiceTypeController;
use App\AiAgents\leedAgent;
use App\Helper;
use App\Http\Controllers\StripeWebhookController;
use Laravel\Cashier\Http\Controllers\WebhookController;



Route::post('/auth/login', [LoginController::class, 'store']);
Route::post('/auth/signup', [RegisteredUserController::class, 'store']);

Route::post('/public/leads', [LeadController::class, 'publicStore']); 
Route::post('/public/appointments', [AppointmentController::class, 'publicStore']);
Route::put('/public/leads/{id}', [LeadController::class, 'publicUpdate']); 
Route::put('/public/appointments/{id}', [AppointmentController::class, 'publicUpdate']);
Route::get('/public/leads/{id}', [LeadController::class, 'publicShow']); 
Route::get('/public/appointments/{id}', [AppointmentController::class, 'publicShow']); 
Route::post('/public/appointments/{id}/convert-to-job', [AppointmentController::class, 'publicConvertToJob']);
Route::get('/public/leads', [LeadController::class, 'publicIndex']);
Route::get('/public/appointments', [AppointmentController::class, 'publicIndexByLead']); 

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::put('/users/{id}/update-password', [UserController::class, 'updatePassword']);
    
    Route::get('/tenant/integration', [AgentIntegrationController::class, 'index']);
    Route::put('/tenant/integration/{agent}', [AgentIntegrationController::class, 'update']);
    Route::post('/tenant/integration/update-google', [AgentIntegrationController::class, 'updateGoogle']);
    Route::post('/tenant/integration/update-twilio', [AgentIntegrationController::class, 'updateTwilio']);
    Route::post('/tenant/integration/update-openai', [AgentIntegrationController::class, 'updateOpenAI']);
    Route::post('/api/tenant/integration/update-outlook', [AgentIntegrationController::class, 'updateOutlook']);
    Route::get('/api/tenant/integration/outlook-token', [AgentIntegrationController::class, 'getOutlookAccessToken']);
    Route::post('/tenant/integration/update-sendgrid', [AgentIntegrationController::class, 'updateSendgrid']);
    
Route::post('/tenant/integration/disconnect', [AgentIntegrationController::class, 'disconnect']);

    Route::get('/tenant', [CompanyController::class, 'index']);
    Route::put('/tenant', [CompanyController::class, 'update']);
});
Route::middleware('auth:sanctum')->post(  '/tenant/phone',
    [UserController::class, 'updateTenantPhone']
);

// Route::get('/chatbot/{botToken}', [ChatbotController::class, 'iframeChatbot']);

Route::middleware('auth:sanctum')->group(function () {
    // Route::get('/profile/update', [UserController::class, 'updateProfile']);
    Route::put('/profile/update', [UserController::class, 'updateProfile']);
    Route::put('/profile/password/update', [UserController::class, 'updatePasswordProfile']);
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('leads', LeadController::class);
    Route::get('/leads', [LeadController::class, 'index']);

});

 Route::middleware('auth:sanctum')->group(function() {
    Route::get('/tenant/sms-template', [TenantSmsTemplateController::class, 'getTemplate']);
    Route::post('/tenant/sms-template', [TenantSmsTemplateController::class, 'storeOrUpdate']);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/lead/summarize-chat', [TwilioController::class, 'summarizeChat']);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/twilio/send-message', [TwilioController::class, 'sendMessage']);
    Route::get('/twilio/messages/{leadId}', [TwilioController::class, 'getMessages']);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tenant/settings', [SettingsController::class, 'index']);
    Route::put('/tenant/settings', [SettingsController::class, 'update']);
    Route::delete('/tenant/settings', [SettingsController::class, 'delete']);
});

Route::post('/twilio/status', [TwilioController::class, 'statusCallback']);
Route::post('/twilio/inbound', [TwilioController::class, 'inbound']);
Route::post('/twilio/voice/inbound', [TwilioController::class, 'handleInboundCall']);
Route::post('/twilio/voice/status', [TwilioController::class, 'handleVoiceStatus']);


Route::post('/n8n/appointment', function (Request $request) {
    \Log::info('N8N Appointment Webhook Hit', $request->all());
    return response()->json(['message' => 'Webhook received', 'data' => $request->all()]);
});

Route::middleware(['auth:sanctum','admin'])->group(function () {
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::put('/appointments/{id}', [AppointmentController::class, 'update']);
    Route::delete('/appointments/{id}', [AppointmentController::class, 'destroy']);
    
});
 Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/appointments/{id}/convert-to-job', [AppointmentController::class, 'convertToJob']);
    Route::get('/jobs', [CrmJobController::class, 'index']);
    Route::put('/jobs/{id}', [CrmJobController::class, 'update']);
    Route::delete('/jobs/{id}', [CrmJobController::class, 'destroy']);
});

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/chatbot/session-info', [ChatbotController::class, 'sessionInfo']);
 Route::post('/chatbot/message', [ChatbotController::class, 'handleMessage']);
});
Route::get('/chatbot/session-info-iframe', [ChatbotController::class, 'sessionInfoIframe']);
Route::post('/chatbot/message-public', [ChatbotController::class, 'handleMessagePublic']);

Route::middleware('auth:sanctum')->group(function () {
    
    
    Route::get('/service-types', function () {
        $types = \App\Models\ServiceType::all();
        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    });
});
    

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
});

Route::apiResource('crm-jobs', CrmJobController::class);
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/ai/summarize', [LeadController::class, 'summarize']);
});

Route::middleware(['auth:sanctum','admin'])->group(function () {
    Route::get('/tenant/integration/google-credentials', [AgentIntegrationController::class, 'getGoogleCredentials']);
    Route::get('/tenant/integration/twilio-credentials', [AgentIntegrationController::class, 'getTwilioCredentials']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/plans', [BillingController::class, 'plans']);
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/service-types', [ServiceTypeController::class, 'index']);
    Route::post('/service-types', [ServiceTypeController::class, 'store']);
    Route::get('/service-types/{serviceType}', [ServiceTypeController::class, 'show']);
    Route::put('/service-types/{serviceType}', [ServiceTypeController::class, 'update']);
    Route::delete('/service-types/{serviceType}', [ServiceTypeController::class, 'destroy']);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/subscription', [BillingController::class, 'getSubscription']);
    Route::post('/subscription/checkout', [BillingController::class, 'checkout']);
    Route::post('/subscription/cancel', [BillingController::class, 'cancelSubscription']);
    Route::post('/subscription/subscribe', [BillingController::class, 'createSubscription']);
    Route::post('/subscription/upgrade', [BillingController::class, 'upgradeSubscription']);
    Route::post('/subscription/resume', [BillingController::class, 'resumeSubscription']);
});

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');


Route::post('/user/subscribe', function (Request $request) {
    $request->user()->newSubscription('default', 'price_monthly')
        ->trialDays(14)
        ->create($request->paymentMethodId);

    // ...
});
Route::middleware(['auth:sanctum', 'subscription'])->group(function () {
    // Leads
    Route::apiResource('leads', LeadController::class);
    Route::get('/leads', [LeadController::class, 'index']);
    Route::post('/ai/summarize', [LeadController::class, 'summarize']);

    // Appointments
    Route::apiResource('appointments', AppointmentController::class);
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::put('/appointments/{id}', [AppointmentController::class, 'update']);
    Route::delete('/appointments/{id}', [AppointmentController::class, 'destroy']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // // Company / Tenant
    // Route::get('/tenant', [CompanyController::class, 'index']);
    // Route::put('/tenant', [CompanyController::class, 'update']);

    // Integrations
    Route::get('/tenant/integration', [AgentIntegrationController::class, 'index']);
    Route::put('/tenant/integration/{agent}', [AgentIntegrationController::class, 'update']);
    Route::post('/tenant/integration/update-google', [AgentIntegrationController::class, 'updateGoogle']);
    Route::post('/tenant/integration/update-twilio', [AgentIntegrationController::class, 'updateTwilio']);
    Route::get('/tenant/integration/google-credentials', [AgentIntegrationController::class, 'getGoogleCredentials']);
    Route::get('/tenant/integration/twilio-credentials', [AgentIntegrationController::class, 'getTwilioCredentials']);

    // Twilio
    Route::post('/twilio/send-message', [TwilioController::class, 'sendMessage']);
    Route::get('/twilio/messages/{leadId}', [TwilioController::class, 'getMessages']);

    // CRM Jobs
    Route::apiResource('crm-jobs', CrmJobController::class);

    // Profile
    Route::get('/profile/update', [UserController::class, 'updateProfile']);
    Route::put('/profile/password/update', [UserController::class, 'updatePasswordProfile']);

    // Service Types
    Route::get('/service-types', function () {
        $types = \App\Models\ServiceType::all();
        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    });
});
