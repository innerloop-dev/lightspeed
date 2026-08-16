<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;

/**
 * Broadcasts from a process with no websocket server in it.
 *
 * This is the interesting direction to demonstrate: an ordinary artisan
 * command, a queue worker, or a scheduled job holds no sockets at all, so the
 * event travels through Redis into whichever workers are holding connections
 * and out to their clients.
 */
class BroadcastHello extends Command
{
    protected $signature = 'hello:broadcast {message=hello from the server}';

    protected $description = 'Broadcast a message to every open tab';

    public function handle(): int
    {
        Broadcast::connection()->broadcast(
            ['presence-lobby'],
            'hello',
            ['message' => $this->argument('message')],
        );

        $this->info('Broadcast sent. Every open tab should have it.');

        return self::SUCCESS;
    }
}
