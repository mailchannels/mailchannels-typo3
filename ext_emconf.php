<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'MailChannels Email API candidate',
    'description' => 'Unreleased direct API transport; not production-ready or registered in TER.',
    'category' => 'services',
    'state' => 'alpha',
    'author' => 'MailChannels',
    'author_email' => 'dev@mailchannels.com',
    'version' => '0.0.0',
    'constraints' => [
        'depends' => ['typo3' => '14.3.7-14.3.7', 'php' => '8.4.0-8.4.99'],
        'conflicts' => [],
        'suggests' => [],
    ],
];
