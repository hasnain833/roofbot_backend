<?php

use App\Http\Controllers\AgentIntegrationController;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\ProfileInformationController;
use App\Http\Controllers\CompanyController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

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
