<?php

namespace App\Services\Nats;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Exception;

class NatsClientFactory
{
    public function make(): Client
    {
        $host  = (string) config('nats.host');
        $port  = (int) config('nats.port');
        $token = config('nats.token');
        $user  = config('nats.user');
        $pass  = config('nats.pass');

        if ($host === '' || $port <= 0) {
            throw new Exception('NATS host/port not configured (NATS_HOST / NATS_PORT).');
        }

        $opts = ['host' => $host, 'port' => $port];

        if (! empty($token)) {
            $opts['token'] = (string) $token;
        } elseif (! empty($user) && ! empty($pass)) {
            $opts['user'] = (string) $user;
            $opts['pass'] = (string) $pass;
        } else {
            throw new Exception('NATS auth not configured — set NATS_TOKEN or both NATS_USER+NATS_PASS.');
        }

        return new Client(new Configuration($opts));
    }
}
