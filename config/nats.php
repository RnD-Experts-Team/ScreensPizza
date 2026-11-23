<?php

$devMode = (int) env('DEV_MODE', 0) === 1;

$authSubject = $devMode ? 'auth.testing.v1.>' : 'auth.v1.>';

return [
    'dev_mode' => $devMode,

    'host'  => env('NATS_HOST', '127.0.0.1'),
    'port'  => (int) env('NATS_PORT', 4222),
    'user'  => env('NATS_USER'),
    'pass'  => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),

    'streams' => [
        [
            'name'           => $devMode
                ? env('NATS_AUTH_STREAM', 'AUTH_TESTING_EVENTS')
                : env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable'        => $devMode
                ? env('NATS_AUTH_DURABLE', 'SCREENS_AUTH_TESTING_CONSUMER')
                : env('NATS_AUTH_DURABLE', 'SCREENS_AUTH_CONSUMER'),
            'filter_subject' => $authSubject,
        ],
    ],

    'pull' => [
        'batch'      => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms'   => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];
