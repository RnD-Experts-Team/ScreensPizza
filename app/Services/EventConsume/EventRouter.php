<?php

namespace App\Services\EventConsume;

use Exception;

class EventRouter
{
    private array $map;

    public function __construct()
    {
        $devMode = (bool) config('nats.dev_mode');
        $prefix  = $devMode ? 'auth.testing.v1' : 'auth.v1';

        $this->map = [
            // User lifecycle
            "{$prefix}.user.created" => \App\Services\EventConsume\Handlers\UserCreatedHandler::class,
            "{$prefix}.user.updated" => \App\Services\EventConsume\Handlers\UserUpdatedHandler::class,
            "{$prefix}.user.deleted" => \App\Services\EventConsume\Handlers\UserDeletedHandler::class,

            // Store lifecycle. No store.updated: it only carries name, metadata and
            // is_active, none of which are kept here (the store number never changes).
            "{$prefix}.store.created" => \App\Services\EventConsume\Handlers\StoreCreatedHandler::class,
            "{$prefix}.store.deleted" => \App\Services\EventConsume\Handlers\StoreDeletedHandler::class,
        ];
    }

    public function resolve(string $subject): string
    {
        return $this->map[$subject]
            ?? throw new Exception("No handler registered for subject '{$subject}'.");
    }

    public function getMap(): array
    {
        return $this->map;
    }
}
