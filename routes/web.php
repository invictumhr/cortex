<?php

use App\Http\Controllers\Admin\TopupCodeBatchExportController;
use App\Http\Controllers\ChatActionController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\ChatPersonaController;
use App\Http\Controllers\PaddleWebhookController;
use App\Http\Controllers\PowerShellController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Payment processor webhook — public, no auth, signed via HMAC in the handler.
Route::post('/paddle/webhook', PaddleWebhookController::class)->name('paddle.webhook');

// Admin: stream a topup-code batch as CSV (plaintext PINs, one per line).
// The controller itself enforces is_admin on top of the auth middleware.
Route::middleware('auth')->get(
    '/admin/topup-batches/{batch}/codes.csv',
    TopupCodeBatchExportController::class,
)->name('admin.topup-batches.export');

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'chats.index' : 'login');
});

// Chat UI and profile editing both require a verified email. Pre-existing
// users (admin@cortex.test seeded with email_verified_at filled in) skip
// the gate cleanly; new signups are bounced to the verify-email page until
// they click the link.
Route::middleware(['auth', 'verified'])->group(function () {
    // Breeze auth controllers redirect here after login.
    Route::get('/dashboard', fn () => redirect()->route('chats.index'))->name('dashboard');

    Route::get('/chats', [ChatController::class, 'index'])->name('chats.index');
    Route::post('/chats', [ChatController::class, 'store'])->name('chats.store');
    Route::get('/chats/{chat}', [ChatController::class, 'show'])->name('chats.show');
    Route::post('/chats/{chat}/archive', [ChatController::class, 'archive'])->name('chats.archive');
    Route::delete('/chats/{chat}', [ChatController::class, 'destroy'])->name('chats.destroy');

    Route::get('/chats/{chat}/messages', [ChatMessageController::class, 'index'])->name('chats.messages.index');
    Route::post('/chats/{chat}/messages', [ChatMessageController::class, 'store'])->name('chats.messages.store');

    Route::put('/chats/{chat}/personas/{persona}', [ChatPersonaController::class, 'update'])->name('chats.personas.update');

    Route::post('/chats/{chat}/pause', [ChatActionController::class, 'pause'])->name('chats.pause');
    Route::post('/chats/{chat}/resume', [ChatActionController::class, 'resume'])->name('chats.resume');
    Route::post('/chats/{chat}/heartbeat', [ChatActionController::class, 'heartbeat'])->name('chats.heartbeat');
    Route::post('/chats/{chat}/leave', [ChatActionController::class, 'leave'])->name('chats.leave');

    Route::post('/chats/{chat}/powershell', [PowerShellController::class, 'execute'])->name('chats.powershell');

    Route::get('/attachments/{attachment}/file', [ChatAttachmentController::class, 'show'])->name('attachments.show');

    Route::post('/kill-switch', [SystemController::class, 'killSwitch'])->name('kill-switch');

    // The user-facing profile, password and delete-account pages now live in
    // the Filament user panel (`/user/...`). The Breeze profile.edit route
    // name is kept alive because middleware redirects, NavLinks and Breeze
    // password-reset emails all still reference it.
    Route::get('/profile', fn () => redirect('/user/profile'))->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
