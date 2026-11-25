<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'MailChannels Email API',
    'description' => 'Alpha mail transport that sends TYPO3 email through the MailChannels Email API. Validated on TYPO3 14.3.10 and PHP 8.4 only; no queued sending or automatic retries.',
    'category' => 'services',
    'state' => 'alpha',
    'author' => 'MailChannels',
    'author_email' => 'dev@mailchannels.com',
    'version' => '0.1.1',
    'constraints' => [
        'depends' => ['typo3' => '14.3.10-14.3.10', 'php' => '8.4.0-8.4.99'],
        'conflicts' => [],
        'suggests' => [],
    ],
];
