<?php

use App\Http\Controllers\VendorRegistrationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/admin-login', '/admin/login')
    ->name('login');

Route::get('/subcontractor-registration/{token}', [VendorRegistrationController::class, 'form'])->where('token', '[a-f0-9]{64}')->middleware('throttle:30,1');
Route::post('/subcontractor-registration/{token}', [VendorRegistrationController::class, 'submit'])->where('token', '[a-f0-9]{64}')->middleware('throttle:5,1');

Route::post('/subcontractor-registration/{token}/request-link', [VendorRegistrationController::class, 'requestLink'])->where('token', '[a-f0-9]{64}')->middleware('throttle:5,1');
