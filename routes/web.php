<?php

use App\Http\Controllers\ChatActionController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\ChatPersonaController;
use App\Http\Controllers\PowerShellController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'chats.index' : 'login');
});

Route::middleware(['auth'])->group(function () {
    // Breeze auth controllers redirect here after login.
    Route::get('/dashboard', fn () => redirect()->route('chats.index'))->name('dashboard');

    Route::get('/chats', [ChatController::class, 'index'])->name('chats.index');
    Route::post('/chats', [ChatController::class, 'store'])->name('chats.store');
    Route::get('/chats/{chat}', [ChatController::class, 'show'])->name('chats.show');
    Route::delete('/chats/{chat}', [ChatController::class, 'destroy'])->name('chats.destroy');

    Route::get('/chats/{chat}/messages', [ChatMessageController::class, 'index'])->name('chats.messages.index');
    Route::post('/chats/{chat}/messages', [ChatMessageController::class, 'store'])->name('chats.messages.store');

    Route::put('/chats/{chat}/personas/{persona}', [ChatPersonaController::class, 'update'])->name('chats.personas.update');

    Route::post('/chats/{chat}/pause', [ChatActionController::class, 'pause'])->name('chats.pause');
    Route::post('/chats/{chat}/resume', [ChatActionController::class, 'resume'])->name('chats.resume');
    Route::patch('/chats/{chat}/rounds', [ChatActionController::class, 'setRounds'])->name('chats.rounds');
    Route::post('/chats/{chat}/add-rounds', [ChatActionController::class, 'addRounds'])->name('chats.add-rounds');

    Route::post('/chats/{chat}/powershell', [PowerShellController::class, 'execute'])->name('chats.powershell');

    Route::get('/attachments/{attachment}/file', [ChatAttachmentController::class, 'show'])->name('attachments.show');

    Route::post('/kill-switch', [SystemController::class, 'killSwitch'])->name('kill-switch');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
