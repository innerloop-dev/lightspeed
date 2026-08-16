<?php

// Fixture standing in for an application's routes/channels.php. The doctor
// reads this file as text and never executes it, so one representative
// definition is the whole of what it needs to see.

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('doc.{id}', function ($user, string $id) {
    return true;
});
