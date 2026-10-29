<?php
// Copied into this disposable site's public/index.php; only downstream mail is substituted.
use MailChannels\Typo3\Mail\ApiTransport;
use TYPO3\CMS\Core\Core\{Bootstrap, Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Mail\{Mailer, TemplatedEmailFactory};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Form\Domain\Finishers\EmailFinisher;
use TYPO3\CMS\Form\Service\{FormValueResolver, TranslationService};

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
SystemEnvironmentBuilder::run();
$container = Bootstrap::init($loader);
if (PHP_SAPI !== 'cli-server' || getcwd() !== __DIR__
    || realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__
    || Environment::getPublicPath() !== __DIR__
    || Environment::getProjectPath() !== dirname(__DIR__)) {
    throw new RuntimeException('Unexpected native HTTP entry paths: ' . json_encode([
        PHP_SAPI, getcwd(), realpath($_SERVER['SCRIPT_FILENAME']), __FILE__,
        Environment::getPublicPath(), Environment::getProjectPath(),
    ]));
}
$original = $GLOBALS['TYPO3_CONF_VARS']['MAIL'];
if ($original['transport'] !== 'null' || $original['dsn'] !== ''
    || $original['transport_spool_type'] !== '') {
    throw new RuntimeException('Disposable persistent mail route is not null');
}
if (GeneralUtility::getInstances()[EmailFinisher::class] ?? []) {
    throw new RuntimeException('EmailFinisher queue was already owned');
}
$guard = $container->get(\TYPO3\CMS\Core\EventDispatcher\ListenerProvider::class)
    ->getAllListenerDefinitions()[\TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent::class]
    ['mailchannels/direct-transport-configuration'] ?? null;
if (($guard['service'] ?? null) !== \MailChannels\Typo3\Mail\ConfigurationGuard::class) {
    throw new RuntimeException('Native mail configuration guard is missing');
}
$settings = array_replace($original, [
    'transport' => ApiTransport::class, 'dsn' => '', 'transport_spool_type' => '',
    'mailchannels_api_key' => 'synthetic-only',
    'mailchannels_allowed_senders' => ['sender@example.com'],
]);
$payloads = [];
$client = new \GuzzleHttp\Client(['handler' => function ($request) use (&$payloads) {
    $body = (string)$request->getBody();
    if (strlen($body) > 65536 || count($payloads) !== 0) {
        throw new RuntimeException('Unexpected synthetic API capture size/count');
    }
    $payloads[] = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(
        202, [], json_encode(['results' => [['index' => 0, 'status' => 'sent']]])
    ));
}]);
$events = $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class);
$email = new EmailFinisher($events, $container->get(TemplatedEmailFactory::class),
    new Mailer(new ApiTransport($settings, $client), $events));
$translation = $container->get(TranslationService::class);
$email->injectTranslationService($translation);
$email->injectFormValueResolver(new FormValueResolver($translation));
$email->injectViewFactory($container->get(\TYPO3\CMS\Core\View\ViewFactoryInterface::class));
$logger = new class extends \Psr\Log\AbstractLogger {
    public array $records = [];
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (count($this->records) >= 8) {
            throw new RuntimeException('Native finisher diagnostic limit exceeded');
        }
        $chain = [];
        $exception = $context['exception'] ?? null;
        while ($exception instanceof Throwable && count($chain) < 8) {
            $row = ['class' => $exception::class, 'code' => $exception->getCode()];
            if ($exception instanceof \MailChannels\Typo3\Mail\TransportFailure) {
                $row['outcome'] = $exception->outcome;
            }
            $chain[] = $row;
            $exception = $exception->getPrevious();
        }
        $this->records[] = ['level' => (string)$level, 'exception_chain' => $chain];
    }
};
$email->setLogger($logger);
GeneralUtility::addInstance(EmailFinisher::class, $email);
$finished = false;
try {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $settings;
    $container->get(\TYPO3\CMS\Core\Http\Application::class)->run();
    $finished = true;
} finally {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $original;
    $queued = GeneralUtility::getInstances()[EmailFinisher::class] ?? [];
    if ($queued !== [] && $queued !== [$email]) {
        throw new RuntimeException('Unexpected EmailFinisher queue during cleanup');
    }
    if ($queued !== []) {
        GeneralUtility::makeInstance(EmailFinisher::class);
    }
    $clean = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] === $original
        && !(GeneralUtility::getInstances()[EmailFinisher::class] ?? []);
    $nativeRequest = $GLOBALS['TYPO3_REQUEST'];
    $session = $nativeRequest->getAttribute('frontend.user')->getSession();
    $requestCookie = $nativeRequest->getCookieParams()['fe_typo_user'] ?? null;
    $diagnostic = [
        'method' => $_SERVER['REQUEST_METHOD'], 'finished' => $finished,
        'request_cookie_sha256' => $requestCookie === null ? null : hash('sha256', $requestCookie),
        'session_identifier_sha256' => hash('sha256', $session->getIdentifier()),
        'anonymous_session_persisted' => $session->isAnonymous()
            && UserSessionManager::create('FE')->isSessionPersisted($session),
        'mail_restored' => $clean, 'guard_registered' => true,
        'email_consumed' => $queued === [], 'payloads' => $payloads,
        'finisher_diagnostics' => $logger->records,
        'sapi' => PHP_SAPI, 'public_path' => Environment::getPublicPath(),
        'project_path' => Environment::getProjectPath(),
    ];
    $written = file_put_contents(dirname(__DIR__) . '/frontend-capture.jsonl',
        json_encode($diagnostic, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    if (!$clean || $written === false || ($payloads !== [] && $queued !== [])) {
        throw new RuntimeException('Native HTTP capture/queue restoration mismatch');
    }
}
