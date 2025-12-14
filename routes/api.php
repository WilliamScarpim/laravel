<?php

use App\Http\Controllers\AnamnesisVersionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AudioController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyDoctorController;
use App\Http\Controllers\ConsultationAuditController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/companies', [CompanyController::class, 'store']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login/verify', [AuthController::class, 'verifyTwoFactor']);
Route::post('/register', [RegistrationController::class, 'store']);
Route::get('/specialties', [SpecialtyController::class, 'index']);
Route::post('/login/resend', [AuthController::class, 'resendTwoFactor']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::match(['get', 'patch'], '/me', [AuthController::class, 'me']);

    Route::prefix('company')
        ->middleware('role:company')
        ->group(function () {
            Route::get('/doctors', [CompanyDoctorController::class, 'index']);
            Route::post('/doctors', [CompanyDoctorController::class, 'store']);
        });

    Route::middleware('role:doctor,admin')->group(function () {
        // Patients
        Route::get('/patients/search', [PatientController::class, 'search']);
        Route::post('/patients', [PatientController::class, 'store']);

        // Consultations
        Route::get('/consultations', [ConsultationController::class, 'index']);
        Route::get('/consultations/{id}', [ConsultationController::class, 'show']);
        Route::post('/consultations', [ConsultationController::class, 'store']);
        Route::patch('/consultations/{id}', [ConsultationController::class, 'update']);
        Route::post('/consultations/{id}/complete', [ConsultationController::class, 'complete']);
        Route::delete('/consultations/{id}', [ConsultationController::class, 'destroy']);
        Route::get('/consultations/{id}/anamneses', [AnamnesisVersionController::class, 'index']);
        Route::get('/consultations/{id}/audits', [ConsultationAuditController::class, 'index']);

        // Documents
        Route::get('/consultations/{id}/documents', [DocumentController::class, 'index']);
        Route::post('/consultations/{id}/documents', [DocumentController::class, 'store']);
        Route::patch('/consultations/{id}/documents', [DocumentController::class, 'patch']);
        Route::post('/consultations/{id}/documents/review', [DocumentController::class, 'review']);
        Route::put('/documents/{id}', [DocumentController::class, 'update']);

        // AI helpers
        Route::get('/audio/test-files', [AudioController::class, 'testFiles']);
        Route::get('/audio/test-files/{file}', [AudioController::class, 'getTestFile']);
        Route::post('/audio/process', [AudioController::class, 'process']);
        Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);
        Route::get('/transcribe/jobs/{id}', [TranscriptionController::class, 'status']);
        Route::post('/transcribe/jobs/{id}/retry', [TranscriptionController::class, 'retry']);
        Route::post('/chat', [ChatController::class, 'respond']);
    });
});
