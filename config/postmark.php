<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Basic Authentication Credentials
    |--------------------------------------------------------------------------
    |
    | Set a username and password here to require authentication for Postmark
    | webhooks. Simply enter a random string for both username and password and
    | configure the webhook URL like
    | https://<username>:<password>@example.com/postmark/*bound
    |
    */
    'auth' => [
        'username' => env('POSTMARK_AUTH_USERNAME', 'TestUser'),
        'password' => env('POSTMARK_AUTH_PASSWORD', 'TestPassword'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spamassassin Threshold
    |--------------------------------------------------------------------------
    |
    | This is the maximum spam score for messages to be forwarded. If messages
    | exceed this spam score, they will not be forwarded. It has been
    | preconfigured with a good starting value, but you may wish to fine tune
    | the value if you get too much spam or legitimate e-mail is being blocked.
    |
    */
    'spamassassin_score' => 5.0,
];