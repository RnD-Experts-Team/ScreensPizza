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
