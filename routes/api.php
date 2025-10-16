<?php

use App\Http\Controllers\AgentIntegrationController;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\ProfileInformationController;
use App\Http\Controllers\CompanyController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\TwilioController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CrmJobController;

Route::post('/auth/login', [LoginController::class, 'store']);
Route::post('/auth/signup', [RegisteredUserController::class, 'store']);


Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::put('/users/{id}/update-password', [UserController::class, 'updatePassword']);
    /**
     * Update Integrations
     */
    Route::get('/tenant/integration', [AgentIntegrationController::class, 'index']);
    Route::put('/tenant/integration/{agent}', [AgentIntegrationController::class, 'update']);
    Route::post('/tenant/integration/update-google', [AgentIntegrationController::class, 'updateGoogle']);
    Route::post('/tenant/integration/update-twilio', [AgentIntegrationController::class, 'updateTwilio']);

    /**
     * Company Settings
     */
    Route::get('/tenant', [CompanyController::class, 'index']);
    Route::put('/tenant/{tenant}', [CompanyController::class, 'update']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile/update', [UserController::class, 'updateProfile']);
    Route::put('/profile/password/update', [UserController::class, 'updatePasswordProfile']);
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('leads', LeadController::class);
    Route::get('/leads', [LeadController::class, 'index']);

});


Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/twilio/send-message', [TwilioController::class, 'sendMessage']);
    Route::get('/twilio/messages/{leadId}', [TwilioController::class, 'getMessages']);
});

Route::post('/twilio/inbound', [TwilioController::class, 'inbound']);


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
// routes/api.php

Route::middleware('auth:sanctum')->group(function () {
    // Existing routes...
    
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
