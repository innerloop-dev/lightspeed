<?php

// Fixture standing in for the routes/channels.php that a text search cannot
// tell apart from a working one: every definition is present in the file and
// every one of them is commented out. `Broadcast::channel(` appears four times
// here and registers nothing.

use Illuminate\Support\Facades\Broadcast;

// Broadcast::channel('doc.{id}', function ($user, string $id) {
//     return true;
// });

/*
Broadcast::channel('lobby', function ($user) {
    return ['id' => $user->id];
});

Broadcast::channel('inbox.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
*/

if (false) {
    Broadcast::channel('never', fn () => true);
}
