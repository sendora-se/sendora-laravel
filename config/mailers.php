<?php

declare(strict_types=1);

/*
 * The `sendora` mailer, merged into `mail.mailers` beside the app's own, so
 * `MAIL_MAILER=sendora` works without editing config/mail.php. An app's own
 * `sendora` mailer wins. A mailer may set its own `token`, `base_url` and
 * `stream`, to send from another server.
 */
return [
    'sendora' => [
        'transport' => 'sendora',
    ],
];
