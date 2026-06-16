<?php

use App\Http\Controllers\Api\ChatsController;
use App\Http\Controllers\Api\DiscussController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\WalletController;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;

/*
| Public REST API — Bearer token auth via the `api.token` middleware alias.
| Tokens are managed in the Filament user panel (/user/api-tokens). All
| endpoints are billed off the token owner's wallet through PersonaResponder
| (for /discuss + /messages) and logged to api_token_usages on every hit.
|
| Scopes (declared in App\Models\ApiToken):
|   cortex:discuss        — POST /discuss
|   cortex:chats.read     — GET  /chats, GET /chats/{id}
|   cortex:chats.write    — POST /chats/{id}/messages, /archive, DELETE /chats/{id}
|   cortex:wallet.read    — GET  /wallet, GET /wallet/transactions
*/

Route::prefix('v1')->group(function () {
    // ---- Roster discovery (any valid token, no scope required) --------------
    // Clients need these to know which persona slugs / model strings the
    // /discuss panel fields accept.
    Route::middleware('api.token')->group(function () {
        Route::get('/personas', [MetaController::class, 'personas'])->name('api.v1.personas');
        Route::get('/models', [MetaController::class, 'models'])->name('api.v1.models');
    });

    // ---- Discuss (kicks off a new boardroom) ---------------------------------
    Route::post('/discuss', DiscussController::class)
        ->middleware('api.token:'.ApiToken::SCOPE_DISCUSS)
        ->name('api.v1.discuss');

    // ---- Chats CRUD (read scope for listing, write scope for mutations) -----
    // API routes bind by public_id (SHA-256 hash) so sequential numeric IDs
    // are never exposed — prevents enumeration even if ownership checks pass.
    Route::middleware('api.token:'.ApiToken::SCOPE_CHATS_READ)->group(function () {
        Route::get('/chats', [ChatsController::class, 'index'])->name('api.v1.chats.index');
        Route::get('/chats/{chat:public_id}', [ChatsController::class, 'show'])->name('api.v1.chats.show');
    });

    Route::middleware('api.token:'.ApiToken::SCOPE_CHATS_WRITE)->group(function () {
        Route::post('/chats/{chat:public_id}/messages', [ChatsController::class, 'storeMessage'])->name('api.v1.chats.messages');
        Route::post('/chats/{chat:public_id}/archive', [ChatsController::class, 'archive'])->name('api.v1.chats.archive');
        Route::delete('/chats/{chat:public_id}', [ChatsController::class, 'destroy'])->name('api.v1.chats.destroy');
    });

    // ---- Wallet (read-only — top-ups & debits happen elsewhere) -------------
    Route::middleware('api.token:'.ApiToken::SCOPE_WALLET_READ)->group(function () {
        Route::get('/wallet', [WalletController::class, 'show'])->name('api.v1.wallet.show');
        Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('api.v1.wallet.transactions');
    });
});
