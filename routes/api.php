<?php

use App\Http\Controllers\Api\DiscussController;
use Illuminate\Support\Facades\Route;

/*
| Public REST API — Bearer token auth via the `api.token` middleware alias.
| Tokens are managed in the Filament user panel (/user/api-tokens). All
| endpoints are billed off the token owner's wallet through PersonaResponder.
*/

Route::prefix('v1')->group(function () {
    Route::post('/discuss', DiscussController::class)
        ->middleware('api.token:cortex:discuss')
        ->name('api.v1.discuss');
});
