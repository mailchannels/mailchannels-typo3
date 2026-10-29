<?php
// Native plugin dispatch; no frontend middleware, browser or page-cache response is exercised.
$loader = require getcwd() . '/vendor/autoload.php';

use MailChannels\Typo3\Mail\ApiTransport;
use TYPO3\CMS\Core\Core\{Bootstrap, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Error\Http\BadRequestException;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Mail\{Mailer, TemplatedEmailFactory};
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\{FrontendTypoScript, TypoScriptService};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\Finishers\{ConfirmationFinisher, EmailFinisher};
use TYPO3\CMS\Form\Service\{FormValueResolver, TranslationService};
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Page\{PageInformation, PageParts};

SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
$count = 0;
function check(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$count;
    echo "PASS $label\n";
}

function formControls(string $html): array
{
    $dom = new DOMDocument();
    if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html,
        LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Stock form markup could not be parsed');
    }
    $forms = $dom->getElementsByTagName('form');
    if ($forms->length !== 1 || $forms->item(0)->getAttribute('action') === '') {
        throw new RuntimeException('Expected one stock form with a generated action');
    }
    $form = $forms->item(0);
    $pairs = [];
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('.//input | .//button', $form) as $control) {
        $type = strtolower($control->getAttribute('type'));
        if ($control->hasAttribute('disabled') || $control->getAttribute('name') === ''
            || in_array($type, ['reset', 'button', 'file'], true)
            || (in_array($type, ['checkbox', 'radio'], true)
                && !$control->hasAttribute('checked'))) {
            continue;
        }
        $pairs[] = rawurlencode($control->getAttribute('name')) . '='
            . rawurlencode($control->getAttribute('value'));
    }
    parse_str(implode('&', $pairs), $fields);
    $action = $form->getAttribute('action');
    parse_str(parse_url($action, PHP_URL_QUERY) ?? '', $query);
    return ['action' => $action, 'query' => $query, 'fields' => $fields, 'dom' => $dom];
}

function dispatch(string $method, int $uid, string $action, array $fields = [],
    bool $cached = false): string
{
    global $container, $mailer, $typoScript, $site, $page;
    parse_str(parse_url($action, PHP_URL_QUERY) ?? '', $query);
    if ($method === 'GET') {
        $query = array_replace_recursive($query, $fields);
    }
    if ($cached) {
        $query['tx_form_formframework']['action'] = 'render';
    }
    $content = GeneralUtility::makeInstance(ContentObjectRenderer::class);
    $content->setUserObjectType($cached ? ContentObjectRenderer::OBJECTTYPE_USER
        : ContentObjectRenderer::OBJECTTYPE_USER_INT);
    $http = (new ServerRequest($action, $method, 'php://input', [], [
        'REMOTE_ADDR' => '127.0.0.1',
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => getcwd() . '/public/index.php',
        'HTTP_HOST' => 'fixture.invalid',
    ]))->withQueryParams($query)->withParsedBody($method === 'POST' ? $fields : [])
        ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
        ->withAttribute('frontend.typoscript', $typoScript)
        ->withAttribute('frontend.page.information', $page)
        ->withAttribute('frontend.page.parts', new PageParts())
        ->withAttribute('site', $site)->withAttribute('language', $site->getDefaultLanguage())
        ->withAttribute('routing', new PageArguments(1, '0', [], [], $query))
        ->withAttribute('currentContentObject', $content);
    $http = $http->withUri($http->getUri()->withQuery(http_build_query($query)));
    $http = $http->withAttribute('normalizedParams', NormalizedParams::createFromRequest($http));
    $user = GeneralUtility::makeInstance(
        \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication::class
    );
    $user->start($http);
    $http = $http->withAttribute('frontend.user', $user);
    $content->setRequest($http);
    $content->start(['uid' => $uid, 'pid' => 1], 'tt_content');
    $translation = $container->get(TranslationService::class);
    $view = $container->get(ViewFactoryInterface::class);
    $events = $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class);
    $email = new EmailFinisher($events,
        $container->get(TemplatedEmailFactory::class), $mailer);
    $confirmation = new ConfirmationFinisher(
        $container->get(ConfigurationManagerInterface::class), $view
    );
    foreach ([$email, $confirmation] as $finisher) {
        $finisher->injectTranslationService($translation);
        $finisher->injectFormValueResolver(new FormValueResolver($translation));
        $finisher->injectViewFactory($view);
    }
    $email->setLogger(new \Psr\Log\NullLogger());
    foreach ([$email, $confirmation] as $finisher) {
        if (GeneralUtility::getInstances()[$finisher::class] ?? []) {
            throw new RuntimeException('Unexpected existing test finisher queue');
        }
        GeneralUtility::addInstance($finisher::class, $finisher);
    }
    $originalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    $GLOBALS['TYPO3_REQUEST'] = $http;
    try {
        $bootstrap = $container->get(\TYPO3\CMS\Extbase\Core\Bootstrap::class);
        $bootstrap->setContentObjectRenderer($content);
        return $bootstrap->run('', ['extensionName' => 'Form', 'pluginName' => 'Formframework'],
            $http);
    } finally {
        // Drain only this request's instances if dispatch fails before the factory consumes them.
        foreach ([$email, $confirmation] as $finisher) {
            while (GeneralUtility::getInstances()[$finisher::class] ?? []) {
                if (GeneralUtility::makeInstance($finisher::class) !== $finisher) {
                    throw new RuntimeException('Unexpected finisher during queue cleanup');
                }
            }
        }
        if ($originalRequest === null) {
            unset($GLOBALS['TYPO3_REQUEST']);
        } else {
            $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
        }
    }
}

function freshForm(string $html, int $uid): bool
{
    $controls = formControls($html);
    $values = $controls['fields']['tx_form_formframework']['controllerfixture-' . $uid] ?? [];
    return ($values['visitor'] ?? null) === '' && ($values['email'] ?? null) === ''
        && ($values['__currentPage'] ?? null) === '1'
        && !str_contains($html, 'CONTROLLER-COMPLETE');
}

$original = $GLOBALS['TYPO3_CONF_VARS']['MAIL'];
try {
    $settings = array_replace($original, [
        'transport' => ApiTransport::class, 'dsn' => '', 'transport_spool_type' => '',
        'mailchannels_api_key' => 'synthetic-only',
        'mailchannels_allowed_senders' => ['sender@example.com'],
    ]);
    $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $settings;
    $payloads = [];
    $client = new \GuzzleHttp\Client(['handler' => function ($request) use (&$payloads) {
        $payloads[] = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(
            202, [], json_encode(['results' => [['index' => 0, 'status' => 'sent']]])
        ));
    }]);
    $mailer = new Mailer(new ApiTransport($settings, $client),
        $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class));
    $site = $container->get(SiteFinder::class)->getSiteByPageId(1);
    $page = new PageInformation();
    $page->setId(1);
    $page->setPageRecord(GeneralUtility::makeInstance(PageRepository::class)->getPage(1));
    $page->setRootLine([$page->getPageRecord()]);
    $page->setLocalRootLine([$page->getPageRecord()]);
    $frontend = 'EXT:form/Resources/Private/Frontend/';
    $plugin = ['view' => [
        'templateRootPaths' => [$frontend . 'Templates/'],
        'partialRootPaths' => [$frontend . 'Partials/'],
        'layoutRootPaths' => [$frontend . 'Layouts/'],
    ], 'settings' => [
        'persistenceIdentifier' =>
            'EXT:mailchannels_email_api_candidate/native/controller-fixture.form.yaml',
        'yamlSettingsOverrides' => ['persistenceManager' => ['allowedExtensionPaths' => [
            'EXT:mailchannels_email_api_candidate/native/',
        ]]],
    ]];
    $typoScript = new FrontendTypoScript(new RootNode(), [], [], []);
    $typoScript->setSetupArray($container->get(TypoScriptService::class)
        ->convertPlainArrayToTypoScriptArray(['plugin' => ['tx_form' => $plugin]]));
    $typoScript->setConfigArray([]);

    $initial = dispatch('GET', 101, 'http://fixture.invalid/');
    $controls = formControls($initial);
    $hidden = $controls['fields']['tx_form_formframework']['controllerfixture-101'] ?? [];
    check(freshForm($initial, 101),
        'controller GET renders persisted fixture with native '
        . 'controllerfixture-101 field namespace');
    check(is_string($hidden['__state'] ?? null) && $hidden['__state'] !== ''
        && ($hidden['__currentPage'] ?? null) === '1' && !isset($hidden['__session'])
        && ($controls['query']['tx_form_formframework']['action'] ?? null) === 'perform',
        'GET markup emits native signed state/current-page submit '
        . 'and perform action with no session');
    check($payloads === [] && !str_contains($initial, 'CONTROLLER-COMPLETE'),
        'initial controller GET records zero API payloads and no completion marker');
    $second = dispatch('GET', 202, 'http://fixture.invalid/');
    check(freshForm($second, 202) && !str_contains($second, 'controllerfixture-101'),
        'second content UID renders controllerfixture-202 namespace distinct from UID101');
    check($payloads === [] && !str_contains($second, 'CONTROLLER-COMPLETE'),
        'second controller GET records zero API payloads and no completion marker');

    $values = ['visitor' => 'Zoë 東京 ✓', 'email' => 'zoe@example.com'];
    $submitted = $controls['fields'];
    $submitted['tx_form_formframework']['controllerfixture-101'] = array_replace($hidden, $values);
    $output = dispatch('POST', 101, $controls['action'], $submitted);
    check(substr_count($output, 'CONTROLLER-COMPLETE') === 1,
        'generated perform route completes via native controller forwarding '
        . 'with one completion marker');
    check(count($payloads) === 1 && !str_contains($output, '<form'),
        'valid native controller POST records exactly one API payload without another form');
    $payload = $payloads[0];
    check($payload['subject'] === 'Contact ' . $values['visitor']
        && $payload['reply_to'] === ['email' => $values['email'], 'name' => $values['visitor']]
        && count($payload['content']) === 2
        && str_contains($payload['content'][0]['value'], $values['visitor'])
        && str_contains($payload['content'][1]['value'], $values['visitor'])
        && str_contains($payload['content'][0]['value'], $values['email'])
        && str_contains($payload['content'][1]['value'], $values['email']),
        'payload preserves exact Unicode visitor/email in subject Reply-To and both bodies');

    $output = dispatch('POST', 202, $controls['action'], $submitted);
    check(freshForm($output, 202),
        'UID101 fields submitted to UID202 render its fresh blank first page with no completion');
    check(count($payloads) === 1,
        'cross-content namespace request records no additional API payload');
    $invalid = $submitted;
    $invalid['tx_form_formframework']['controllerfixture-101']['email'] = 'invalid-email';
    $output = dispatch('POST', 101, $controls['action'], $invalid);
    $invalidControls = formControls($output);
    $errors = new DOMXPath($invalidControls['dom']);
    check($errors->query('//*[@id="controllerfixture-101-email-errors" and @role="alert"]')
        ->length === 1
        && ($invalidControls['fields']['tx_form_formframework']['controllerfixture-101']['email']
            ?? '') === 'invalid-email',
        'native controller returns email-associated validator markup on the form first page');
    check(count($payloads) === 1 && !str_contains($output, 'CONTROLLER-COMPLETE'),
        'malformed email response has no completion and records no additional API payload');

    $missing = $submitted;
    unset($missing['tx_form_formframework']['controllerfixture-101']['__state']);
    $output = dispatch('POST', 101, $controls['action'], $missing);
    $returned = formControls($output)['fields']['tx_form_formframework']['controllerfixture-101'];
    // Stock Fluid replays original arguments after perform forwards, even with blank runtime state.
    check($returned['__state'] === $hidden['__state'] && $returned['__currentPage'] === '1'
        && $returned['visitor'] === $values['visitor'] && $returned['email'] === $values['email'],
        'missing-state POST returns first page and blank-state bytes with stock replayed input');
    check(count($payloads) === 1 && !str_contains($output, 'CONTROLLER-COMPLETE'),
        'missing state response has no completion and records no additional API payload');
    $tampered = $submitted;
    $state = &$tampered['tx_form_formframework']['controllerfixture-101']['__state'];
    $state[0] = $state[0] === 'A' ? 'B' : 'A';
    unset($state);
    $rejected = false;
    ob_start();
    try {
        $output = dispatch('POST', 101, $controls['action'], $tampered);
    } catch (BadRequestException $exception) {
        $rejected = $exception->getCode() === 1581862823;
    } finally {
        $buffer = ob_get_clean();
    }
    check($rejected, 'controller dispatch rejects altered native state '
        . 'with BadRequestException1581862823');
    check(count($payloads) === 1 && !str_contains($buffer, 'CONTROLLER-COMPLETE'),
        'tampered state handling produces no completion output or additional API payload');
    $output = dispatch('GET', 101, $controls['action'], $submitted);
    $returned = formControls($output)['fields']['tx_form_formframework']['controllerfixture-101'];
    check($returned['__state'] === $hidden['__state'] && $returned['__currentPage'] === '1'
        && $returned['visitor'] === $values['visitor'] && $returned['email'] === $values['email'],
        'signed GET returns first page and blank-state bytes with stock replayed input');
    check(count($payloads) === 1 && !str_contains($output, 'CONTROLLER-COMPLETE'),
        'signed GET response has no completion and records no additional API payload');
    $output = dispatch('POST', 101, $controls['action'], $submitted, true);
    check(freshForm($output, 101),
        'actual cacheable render route under USER returns a fresh blank first-page form');
    check(count($payloads) === 1 && !str_contains($output, 'CONTROLLER-COMPLETE'),
        'cached render POST ignores genuine signed fields with no completion or extra API payload');
} finally {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $original;
}
if ($GLOBALS['TYPO3_CONF_VARS']['MAIL'] !== $original) {
    throw new RuntimeException('Runtime mail settings were not restored');
}
echo "TYPO3_FORM_CONTROLLER_COMPLETE $count checks; no provider calls\n";
