<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailBatchController;
use App\Http\Controllers\SmtpSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/', fn () => redirect('/batches'))->middleware('auth');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/batches', [EmailBatchController::class, 'index'])->name('batches.index');
    Route::get('/batches/sample-download', [EmailBatchController::class, 'downloadSample'])->name('batches.sample');
    Route::post('/batches/upload', [EmailBatchController::class, 'upload'])->name('batches.upload');
    Route::get('/batches/{batch}/compose', [EmailBatchController::class, 'compose'])->name('batches.compose');
    Route::post('/batches/{batch}/send', [EmailBatchController::class, 'send'])->name('batches.send');
    Route::get('/batches/{batch}', [EmailBatchController::class, 'show'])->name('batches.show');
    Route::get('/batches/{batch}/export', [EmailBatchController::class, 'exportReport'])->name('batches.export');
    Route::delete('/batches/{batch}', [EmailBatchController::class, 'destroy'])->name('batches.destroy');

    Route::get('/smtp', [SmtpSettingController::class, 'index'])->name('smtp.index');
    Route::get('/smtp/create', [SmtpSettingController::class, 'create'])->name('smtp.create');
    Route::post('/smtp', [SmtpSettingController::class, 'store'])->name('smtp.store');
    Route::get('/smtp/{smtp}/edit', [SmtpSettingController::class, 'edit'])->name('smtp.edit');
    Route::put('/smtp/{smtp}', [SmtpSettingController::class, 'update'])->name('smtp.update');
    Route::post('/smtp/{smtp}/activate', [SmtpSettingController::class, 'activate'])->name('smtp.activate');
    Route::post('/smtp/{smtp}/test', [SmtpSettingController::class, 'sendTest'])->name('smtp.test');
    Route::delete('/smtp/{smtp}', [SmtpSettingController::class, 'destroy'])->name('smtp.destroy');
});
