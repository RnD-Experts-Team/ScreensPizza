<?php

namespace App\Console\Commands;

use App\Services\EventConsume\JetStreamConsumer;
use Illuminate\Console\Command;

class NatsConsumeCommand extends Command
{
    protected $signature   = 'nats:consume';
    protected $description = 'Consume Auth Service user events from NATS JetStream';

    public function handle(JetStreamConsumer $consumer): int
    {
        $this->info('Starting NATS consumer — Screens Service');
        $this->info('Stream : '.config('nats.streams.0.name'));
        $this->info('Durable: '.config('nats.streams.0.durable'));
        $this->info('Press Ctrl+C to stop.');
        $this->line('');

        $consumer->runForever();

        return self::SUCCESS;
    }
}
