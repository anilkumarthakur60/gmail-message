<?php

use App\Http\Controllers\GoogleController;
use Illuminate\Support\Facades\Route;

Route::get('', function () {
    return view('welcome');
})->name('index');
Route::get('login', [GoogleController::class, 'login'])->name('google.login');
Route::get('callback', [GoogleController::class, 'callback'])->name('google.callback');
Route::get('emails', [GoogleController::class, 'emails'])->name('google.emails');
Route::get('thread/{id}', [GoogleController::class, 'thread'])->name('google.thread');

Route::get('/download-attachment', [GoogleController::class, 'downloadAttachment'])->name('google.download-attachment');
