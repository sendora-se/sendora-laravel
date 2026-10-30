<?php

declare(strict_types=1);

/*
 * Sendora's settings. A key left out of the environment falls back to the
 * SDK's own reading of it, and a client built without one throws, naming
 * the variable.
 */
return [
    // The server key, `sk_…`, which sends and reads everything of its server.
    'token' => env('SENDORA_API_TOKEN'),

    // The account key, `ak_…`, which manages the account's servers, keys and domains.
    'account_token' => env('SENDORA_ACCOUNT_TOKEN'),

    // The API's address; https://api.sendora.se when not set.
    'base_url' => env('SENDORA_BASE_URL'),

    // The seconds an attempt may take. Three attempts and their waits stay
    // within `queue:work`'s default timeout of 60 seconds.
    'timeout' => 10,

    // How often a failed call is repeated when repeating is safe; 0 turns retries off.
    'max_retries' => 2,

    // A PSR-18 client in the container, by its name, for a proxy or for tests; curl when null.
    'http_client' => null,

    // The stream that mail through `MAIL_MAILER=sendora` goes on; the server's
    // default transactional stream when not set. A mailer may name its own.
    'stream' => env('SENDORA_STREAM'),

    // The route `POST /sendora/webhook`, named `sendora.webhook`, which checks
    // each delivery's signature and fires its event. It exists only once a
    // secret is set; run `route:cache` again after you first set one.
    'webhook' => [
        'enabled' => true,

        'path' => 'sendora/webhook',

        // The domain that the route answers on; any when null.
        'domain' => null,

        // Middleware for the route, such as a rate limit. It is outside the
        // `web` group, so Laravel's CSRF check never applies to it.
        'middleware' => [],

        // The signing secrets, `whsec_…`, separated by commas, since each
        // webhook has its own. A delivery that verifies under any is taken.
        'secrets' => env('SENDORA_WEBHOOK_SECRET'),

        // How old a signature may be, in seconds, either way.
        'tolerance' => 300,
    ],
];
