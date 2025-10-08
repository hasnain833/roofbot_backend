<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::post('/auth/login', [LoginController::class, 'store']);


Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);      // List all users
    Route::post('/users', [UserController::class, 'store']);     // Create new user
    Route::get('/users/{id}', [UserController::class, 'show']);  // Show single user
    Route::put('/users/{id}', [UserController::class, 'update']); // Update user
    Route::delete('/users/{id}', [UserController::class, 'destroy']); // Delete user
});
use App\Http\Controllers\Auth\ProfileInformationController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [ProfileInformationController::class, 'show']);
    Route::put('/profile', [ProfileInformationController::class, 'update']);
});

