<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;

/**
 * Broadcasts to one user's private channel, from outside the server.
 *
 * The presence broadcast (hello:broadcast) proves fan-out. This one proves the
 * same path lands on a private channel that Echo.private() had to authorize
 * for before it could hear anything.
 */
class InboxHello extends Command
{
    protected $signature = 'hello:inbox {user} {message=you have mail}';

    protected $description = 'Broadcast to one user private inbox channel';

    public function handle(): int
    {
        Broadcast::connection()->broadcast(
            ['private-inbox.'.$this->argument('user')],
            'mail',
            ['message' => $this->argument('message'), 'at' => now()->toTimeString()],
        );

        $this->info('Sent to private-inbox.'.$this->argument('user'));

        return self::SUCCESS;
    }
}
