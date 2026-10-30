<?php
// Constructed FormDefinition::bind()->render() under native FormRuntime state handling.
// No controller dispatch, frontend middleware, page cache, browser or provider traffic.
$loader = require getcwd() . '/vendor/autoload.php';

use MailChannels\Typo3\Mail\ApiTransport;
use Symfony\Component\Yaml\Yaml;
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
use TYPO3\CMS\Extbase\Mvc\{ExtbaseRequestParameters, Request};
use TYPO3\CMS\Form\Domain\Factory\ArrayFormFactory;
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

const FORM = 'submissionfixture';
const MARKER = 'SUBMISSION-COMPLETE';

/** Hidden and text controls of the one rendered form, keyed like PHP would parse them. */
function controls(string $html): array
{
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $pairs = [];
    foreach ((new DOMXPath($dom))->query('//form//input | //form//button') as $control) {
        $type = strtolower($control->getAttribute('type'));
        if ($control->getAttribute('name') === '' || in_array($type, ['reset', 'button', 'file'], true)) {
            continue;
        }
        $pairs[] = rawurlencode($control->getAttribute('name')) . '='
            . rawurlencode($control->getAttribute('value'));
    }
    parse_str(implode('&', $pairs), $fields);
    return $fields['tx_form_formframework'][FORM] ?? [];
}

/**
 * Builds the fixture form, binds it to one request and renders it.
 * $context: 'USER_INT', 'USER' or null (no surrounding content object).
 * Returns [runtime, html, payloads sent during this request].
 */
function submit(string $method, array $fields, ?string $context = 'USER_INT'): array
{
    global $container, $mailer, $typoScript, $site, $page, $payloads, $definition;
    $before = count($payloads);
    $http = (new ServerRequest('http://fixture.invalid/', $method, 'php://input', [], [
        'REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => getcwd() . '/public/index.php', 'HTTP_HOST' => 'fixture.invalid',
    ]))->withParsedBody($method === 'POST' ? ['tx_form_formframework' => [FORM => $fields]] : [])
        ->withQueryParams($method === 'GET' ? ['tx_form_formframework' => [FORM => $fields]] : [])
        ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
        ->withAttribute('frontend.typoscript', $typoScript)
        ->withAttribute('frontend.page.information', $page)
        ->withAttribute('frontend.page.parts', new PageParts())
        ->withAttribute('site', $site)->withAttribute('language', $site->getDefaultLanguage())
        ->withAttribute('routing', new PageArguments(1, '0', [], [], []));
    $http = $http->withAttribute('normalizedParams', NormalizedParams::createFromRequest($http));
    $user = GeneralUtility::makeInstance(\TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication::class);
    $user->start($http);
    $http = $http->withAttribute('frontend.user', $user);
    if ($context !== null) {
        $content = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $content->setUserObjectType($context === 'USER' ? ContentObjectRenderer::OBJECTTYPE_USER
            : ContentObjectRenderer::OBJECTTYPE_USER_INT);
        $content->setRequest($http);
        $content->start(['uid' => 101, 'pid' => 1], 'tt_content');
        $http = $http->withAttribute('currentContentObject', $content);
    }
    // Mirror Extbase namespace extraction: the plugin arguments, as RequestBuilder would set them.
    $parameters = (new ExtbaseRequestParameters())->setPluginName('Formframework')
        ->setControllerExtensionName('Form')->setControllerName('FormFrontend')
        ->setControllerActionName('perform')->setArguments([FORM => $fields]);
    $request = new Request($http->withAttribute('extbase', $parameters));

    $translation = $container->get(TranslationService::class);
    $view = $container->get(ViewFactoryInterface::class);
    $email = new EmailFinisher($container->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
        $container->get(TemplatedEmailFactory::class), $mailer);
    $confirmation = new ConfirmationFinisher($container->get(ConfigurationManagerInterface::class), $view);
    foreach ([$email, $confirmation] as $finisher) {
        $finisher->injectTranslationService($translation);
        $finisher->injectFormValueResolver(new FormValueResolver($translation));
        $finisher->injectViewFactory($view);
        GeneralUtility::addInstance($finisher::class, $finisher);
    }
    $email->setLogger(new \Psr\Log\NullLogger());
    $previous = $GLOBALS['TYPO3_REQUEST'] ?? null;
    $GLOBALS['TYPO3_REQUEST'] = $request;
    try {
        $form = $container->get(ArrayFormFactory::class)->build($definition, 'standard', $request);
        $runtime = $form->bind($request);
        $html = (string)$runtime->render();
    } finally {
        // The factory consumes queued finishers only when it builds them; drain any leftovers.
        foreach ([$email, $confirmation] as $finisher) {
            while (GeneralUtility::getInstances()[$finisher::class] ?? []) {
                GeneralUtility::makeInstance($finisher::class);
            }
        }
        if ($previous === null) {
            unset($GLOBALS['TYPO3_REQUEST']);
        } else {
            $GLOBALS['TYPO3_REQUEST'] = $previous;
        }
    }
    return [$runtime, $html, array_slice($payloads, $before)];
}

function errorCodes(\TYPO3\CMS\Form\Domain\Runtime\FormRuntime $runtime): array
{
    $result = $runtime->getRequest()->getAttribute('extbase')->getOriginalRequestMappingResults();
    $codes = [];
    foreach ($result->getFlattenedErrors() as $errors) {
        foreach ($errors as $error) {
            $codes[] = $error->getCode();
        }
    }
    return $codes;
}

function firstPage(\TYPO3\CMS\Form\Domain\Runtime\FormRuntime $runtime): bool
{
    return $runtime->getCurrentPage()?->getIndex() === 0;
}

function nothingSent(array $sent, string $html): bool
{
    return $sent === [] && !str_contains($html, MARKER);
}

$original = $GLOBALS['TYPO3_CONF_VARS']['MAIL'];
try {
    $settings = array_replace($original, [
        'transport' => ApiTransport::class, 'dsn' => '', 'transport_spool_type' => '',
        'mailchannels_api_key' => 'synthetic-only', 'mailchannels_allowed_senders' => ['sender@example.com'],
    ]);
    $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $settings;
    $payloads = [];
    $client = new \GuzzleHttp\Client(['handler' => function ($request) use (&$payloads) {
        $payloads[] = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(
            202, [], json_encode(['results' => [['index' => 0, 'status' => 'sent']]])));
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
    $typoScript = new FrontendTypoScript(new RootNode(), [], [], []);
    $typoScript->setSetupArray($container->get(TypoScriptService::class)->convertPlainArrayToTypoScriptArray(
        ['plugin' => ['tx_form' => ['view' => [
            'templateRootPaths' => [$frontend . 'Templates/'],
            'partialRootPaths' => [$frontend . 'Partials/'],
            'layoutRootPaths' => [$frontend . 'Layouts/'],
        ]]]]));
    $typoScript->setConfigArray([]);
    $definition = Yaml::parseFile(__DIR__ . '/controller-fixture.form.yaml');
    $definition['identifier'] = FORM;
    $definition['finishers'][1]['options']['message'] = MARKER;

    // Initial GET under USER_INT: first page, signed state, no session yet.
    [$runtime, $html, $sent] = submit('GET', []);
    $hidden = controls($html);
    check(firstPage($runtime) && nothingSent($sent, $html),
        'initial GET retains first page without dispatch or completion');
    check(is_string($hidden['__state'] ?? null) && $hidden['__state'] !== ''
        && ($hidden['__currentPage'] ?? null) === '1' && !isset($hidden['__session']),
        'initial GET emits native signed state and current-page submit value');

    $values = ['visitor' => 'Zoë 東京 ✓', 'email' => 'zoe@example.com'];
    $valid = array_replace($hidden, $values);
    [$runtime, $html, $sent] = submit('POST', $valid);
    check($runtime['visitor'] === $values['visitor'] && $runtime['email'] === $values['email'],
        'valid POST binds exact request values including Unicode');
    check($runtime->getCurrentPage() === null && errorCodes($runtime) === [],
        'valid POST passes native validation and completes last page');
    check(count($sent) === 1 && substr_count($html, MARKER) === 1,
        'valid POST dispatches once and reaches following completion finisher');
    $payload = $sent[0];
    check($payload['subject'] === 'Contact ' . $values['visitor']
        && $payload['reply_to'] === ['email' => $values['email'], 'name' => $values['visitor']]
        && count($payload['content']) === 2
        && str_contains($payload['content'][0]['value'], $values['visitor'])
        && str_contains($payload['content'][1]['value'], $values['visitor']),
        'valid POST resolves subject Reply-To and both mail bodies from submitted values');

    // Cached contexts: TYPO3 discards submissions unless the content object is USER_INT.
    [$runtime, $html, $sent] = submit('GET', [], 'USER');
    $cached = controls($html);
    check(firstPage($runtime) && $runtime->getFormSession() === null && nothingSent($sent, $html),
        'cached GET renders first page without a form session mail or completion');
    check(is_string($cached['__state'] ?? null) && $cached['__state'] !== ''
        && ($cached['__currentPage'] ?? null) === '1' && !isset($cached['__session']),
        'cached GET emits genuine signed state and submit value without session token');
    $cachedValid = array_replace($cached, $values);
    foreach (['USER' => 'explicit cached content', null => 'missing content object'] as $context => $label) {
        [$runtime, $html, $sent] = submit('POST', $cachedValid, $context === '' ? null : $context);
        check(firstPage($runtime) && $runtime->getFormSession() === null && $runtime['visitor'] === null && $runtime['email'] === null,
            "$label POST discards signed state and values into fresh first page");
        check(nothingSent($sent, $html) && str_contains($html, '<form'),
            "$label POST rerenders native form with zero mail and zero completion");
    }
    [$runtime, $html, $sent] = submit('POST', $cachedValid);
    check($runtime['visitor'] === $values['visitor'] && $runtime['email'] === $values['email'],
        'uncached recovery binds exact values from the same cached GET signed submission');
    check($runtime->getCurrentPage() === null && errorCodes($runtime) === []
        && $runtime->getFormSession() !== null,
        'uncached recovery passes native validation completes and initializes form session');
    check(count($sent) === 1 && substr_count($html, MARKER) === 1
        && $sent[0]['subject'] === $payload['subject'] && $sent[0]['reply_to'] === $payload['reply_to'],
        'uncached recovery sends exactly once reaches completion and preserves resolved payload');

    $withoutVisitor = $valid;
    unset($withoutVisitor['visitor']);
    // An omitted field is null (NotEmpty 1221560910); an empty string would be 1221560718.
    $cases = [
        'malformed email' => [array_replace($valid, ['email' => 'invalid-email']), 1221559976],
        'missing visitor' => [$withoutVisitor, 1221560910],
    ];
    foreach ($cases as $label => [$fields, $code]) {
        [$runtime, $html, $sent] = submit('POST', $fields);
        check(firstPage($runtime) && in_array($code, errorCodes($runtime), true),
            "$label retains first page with native validator error");
        check(nothingSent($sent, $html), "$label dispatches zero mail and never reaches completion finisher");
    }

    $missing = $valid;
    unset($missing['__state']);
    [$runtime, $html, $sent] = submit('POST', $missing);
    check(firstPage($runtime) && $runtime['visitor'] === null && errorCodes($runtime) === [],
        'missing state ignores submitted values and retains fresh first page');
    check(nothingSent($sent, $html), 'missing state dispatches zero mail and never reaches completion finisher');

    $tampered = $valid;
    $tampered['__state'][0] = $tampered['__state'][0] === 'A' ? 'B' : 'A';
    $before = count($payloads);
    $rejected = false;
    try {
        submit('POST', $tampered);
    } catch (BadRequestException $e) {
        $rejected = $e->getCode() === 1581862823;
    }
    check($rejected, 'tampered state throws native BadRequestException 1581862823');
    check(count($payloads) === $before, 'tampered state dispatches zero mail and never reaches completion finisher');

    [$runtime, $html, $sent] = submit('GET', $valid);
    check(firstPage($runtime) && $runtime['visitor'] === null && errorCodes($runtime) === [],
        'state on GET ignores submitted values and retains fresh first page');
    check(nothingSent($sent, $html), 'state on GET dispatches zero mail and never reaches completion finisher');
} finally {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $original;
}
echo "TYPO3_FORM_SUBMISSION_COMPLETE $count checks; no provider calls\n";
