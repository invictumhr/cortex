<?php

use App\Models\Chat;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * A user may listen on their own chat channel only.
 */
Broadcast::channel('chat.{chatId}', function ($user, int $chatId) {
    return Chat::query()
        ->whereKey($chatId)
        ->where('user_id', $user->id)
        ->exists();
});
