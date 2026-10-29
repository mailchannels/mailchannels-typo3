<?php
use GuzzleHttp\{Client, Cookie\CookieJar};
use TYPO3\CMS\Core\Core\{Bootstrap, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Database\ConnectionPool;

$loader = require getcwd() . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
$manifest = json_decode(file_get_contents('frontend-setup.json'), true, 512, JSON_THROW_ON_ERROR);
$namespace = 'frontendfixture-' . $manifest['content_uid'];
$prefix = 'tx_form_formframework[' . $namespace . ']';
$count = 0;
$observations = [];
$client = new Client([
    'base_uri' => 'http://127.0.0.1:8080/', 'connect_timeout' => 2, 'timeout' => 15,
    'allow_redirects' => false, 'http_errors' => false, 'proxy' => '',
]);
function check(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$count;
    echo "PASS $label\n";
}
function capture(): array
{
    $lines = is_file('frontend-capture.jsonl')
        ? file('frontend-capture.jsonl', FILE_IGNORE_NEW_LINES) : [];
    return array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
}
function payloads(): array
{
    return array_merge([], ...array_column(capture(), 'payloads'));
}
function inventory(): array
{
    global $container;
    $rows = $container->get(ConnectionPool::class)->getConnectionForTable('sys_file')
        ->executeQuery('SELECT uid,storage,identifier,name,size,sha1 FROM sys_file ORDER BY uid')
        ->fetchAllAssociative();
    return ['files' => $rows, 'folders' => glob('public/fileadmin/user_upload/form_*') ?: []];
}
function controls(string $html): array
{
    $dom = new DOMDocument();
    if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html,
        LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Stock HTTP form markup could not be parsed');
    }
    $forms = $dom->getElementsByTagName('form');
    if ($forms->length !== 1) {
        throw new RuntimeException('Expected one stock HTTP form');
    }
    $form = $forms->item(0);
    $fields = [];
    $file = '';
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('.//input | .//button', $form) as $control) {
        $name = $control->getAttribute('name');
        $type = strtolower($control->getAttribute('type'));
        if ($type === 'file') {
            $file = $name;
        }
        if ($name === '' || $control->hasAttribute('disabled')
            || in_array($type, ['reset', 'button', 'file'], true)
            || (in_array($type, ['checkbox', 'radio'], true)
                && !$control->hasAttribute('checked'))) {
            continue;
        }
        if (isset($fields[$name])) {
            throw new RuntimeException('Unexpected duplicate successful control');
        }
        $fields[$name] = $control->getAttribute('value');
    }
    return ['action' => $form->getAttribute('action'), 'fields' => $fields,
        'file' => $file, 'xpath' => $xpath];
}
function request(string $case, CookieJar $jar, ?array $form = null, array $values = [],
    ?array $upload = null, bool $omitState = false): string
{
    global $client, $prefix, $observations;
    $before = count(capture());
    $options = ['cookies' => $jar];
    $action = '/';
    if ($form !== null) {
        $action = $form['action'];
        $uri = \GuzzleHttp\Psr7\UriResolver::resolve(
            new \GuzzleHttp\Psr7\Uri('http://127.0.0.1:8080/'),
            new \GuzzleHttp\Psr7\Uri($action)
        )->withFragment('');
        if ($uri->getHost() !== '127.0.0.1' || $uri->getPort() !== 8080
            || $uri->getScheme() !== 'http') {
            throw new RuntimeException('Generated form action left owned loopback');
        }
        $action = (string)$uri;
        $fields = $form['fields'];
        foreach ($values as $name => $value) {
            $fields[$prefix . '[' . $name . ']'] = $value;
        }
        if ($omitState) {
            unset($fields[$prefix . '[__state]']);
        }
        $options['multipart'] = [];
        foreach ($fields as $name => $value) {
            $options['multipart'][] = ['name' => $name, 'contents' => $value];
        }
        foreach ($upload === null ? [] : (isset($upload['name']) ? [$upload] : $upload) as $file) {
            $options['multipart'][] = [
                'name' => $form['file'], 'contents' => $file['bytes'],
                'filename' => $file['name'], 'headers' => ['Content-Type' => $file['mime']],
            ];
        }
    }
    $response = $client->request($form === null ? 'GET' : 'POST', $action, $options);
    $html = (string)$response->getBody();
    $deadline = microtime(true) + 2;
    do {
        $captures = capture();
        if (count($captures) === $before + 1) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (count($captures) !== $before + 1 || $response->getStatusCode() !== 200) {
        throw new RuntimeException($case . ' status/capture mismatch: '
            . $response->getStatusCode());
    }
    $cookies = [];
    foreach ($jar->toArray() as $cookie) {
        $cookie['Value'] = hash('sha256', $cookie['Value']);
        $cookies[] = $cookie;
    }
    $headers = $response->getHeaders();
    $headers['Set-Cookie'] = array_map(function ($line) {
        [$pair, $attributes] = array_pad(explode(';', $line, 2), 2, '');
        [$name, $value] = explode('=', $pair, 2);
        return $name . '=sha256:' . hash('sha256', $value) . ';' . $attributes;
    }, $response->getHeader('Set-Cookie'));
    $observations[$case] = [
        'status' => $response->getStatusCode(), 'headers' => $headers, 'cookies' => $cookies,
        'response_sha256' => hash('sha256', $html), 'inventory' => inventory(),
        'payload_count' => count(payloads()), 'capture' => end($captures),
    ];
    return $html;
}
function noCompletion(string $html): bool
{
    return !str_contains($html, 'FRONTEND-HTTP-COMPLETE');
}
function errorFor(array $form, string $name): bool
{
    global $namespace;
    return $form['xpath']->query('//*[@id="' . $namespace . '-' . $name
        . '-errors" and @role="alert"]')->length === 1;
}

try {
    $initialInventory = inventory();
    $initialPayloads = count(payloads());
    if ($initialPayloads !== 0) {
        throw new RuntimeException('Fresh HTTP fixture already captured mail');
    }
    $jar = new CookieJar();
    $html = request('fresh_http_get', $jar);
    $form = controls($html);
    parse_str(parse_url($form['action'], PHP_URL_QUERY) ?? '', $query);
    check($form['file'] === $prefix . '[attachment]'
        && ($form['fields'][$prefix . '[__currentPage]'] ?? null) === '1'
        && ($form['fields'][$prefix . '[__state]'] ?? '') !== ''
        && !isset($form['fields'][$prefix . '[__session]'])
        && ($query['tx_form_formframework']['action'] ?? '') === 'perform',
        'fresh_http_get: stock UID namespace generated perform/state/current-page; no session');
    check(noCompletion($html) && payloads() === [] && inventory() === $initialInventory,
        'fresh_http_get: no completion API payload or FAL upload');
    $html = request('warm_http_get', $jar);
    $warm = controls($html);
    check($warm['file'] === $form['file']
        && ($warm['fields'][$prefix . '[visitor]'] ?? null) === ''
        && ($warm['fields'][$prefix . '[email]'] ?? null) === '',
        'warm_http_get: blank stock form preserves native field names');
    check(noCompletion($html) && payloads() === [] && inventory() === $initialInventory,
        'warm_http_get: no completion API payload or FAL upload; no cache-hit claim');
    $bytes = "Native HTTP attachment\n";
    $upload = ['name' => 'attachment.txt', 'bytes' => $bytes, 'mime' => 'text/plain'];
    $values = ['visitor' => 'Zoë 東京 ✓', 'email' => 'invalid-email'];
    $html = request('ordinary_validation_error_with_upload', $jar, $warm, $values, $upload);
    $returned = controls($html);
    check(errorFor($returned, 'email') && noCompletion($html),
        'ordinary_validation_error_with_upload: native email alert; no completion');
    $pointerNames = array_filter(array_keys($returned['fields']),
        fn($name) => str_contains($name, '[__submittedFiles]'));
    check(($returned['fields'][$prefix . '[__session]'] ?? '') !== ''
        && count($pointerNames) === 1
        && $returned['fields'][reset($pointerNames)] !== ''
        && str_contains($html, 'attachment.txt'),
        'ordinary_validation_error_with_upload: native session/pointer and upload preview');
    $afterUpload = inventory();
    $newFiles = array_values(array_udiff($afterUpload['files'], $initialInventory['files'],
        fn($a, $b) => $a['uid'] <=> $b['uid']));
    $newFolders = array_values(array_diff($afterUpload['folders'], $initialInventory['folders']));
    check(payloads() === [] && count($newFiles) === 1 && count($newFolders) === 1
        && $newFiles[0]['name'] === 'attachment.txt'
        && (int)$newFiles[0]['storage'] === 1
        && file_get_contents('public/fileadmin' . $newFiles[0]['identifier']) === $bytes,
        'ordinary_validation_error_with_upload: exactly one native FAL file/folder; exact bytes');
    $cookie = $jar->getCookieByName('fe_typo_user');
    $captures = capture();
    $uploadCapture = end($captures);
    check($cookie !== null && $cookie->getValue() !== ''
        && $uploadCapture['anonymous_session_persisted'],
        'ordinary_validation_error_with_upload: emitted cookie/native anonymous session persisted');
    $values['email'] = 'zoe@example.com';
    $html = request('same_submitter_corrects_form', $jar, $returned,
        ['email' => $values['email']]);
    $captures = capture();
    $correctionCapture = end($captures);
    check(substr_count($html, 'FRONTEND-HTTP-COMPLETE') === 1
        && $correctionCapture['request_cookie_sha256'] === hash('sha256', $cookie->getValue())
        && $correctionCapture['session_identifier_sha256']
            === $uploadCapture['session_identifier_sha256']
        && $correctionCapture['anonymous_session_persisted'],
        'same_submitter_corrects_form: actual cookie/session; email-only correction completes');
    $payload = payloads()[0] ?? [];
    $attachment = $payload['attachments'][0] ?? [];
    check(count(payloads()) === 1 && $payload['subject'] === 'Contact ' . $values['visitor']
        && $payload['reply_to'] === ['email' => $values['email'], 'name' => $values['visitor']]
        && $payload['content'] === [
            ['type' => 'text/plain', 'value' => 'Contact from ' . $values['visitor']
                . ': ' . $values['email']],
            ['type' => 'text/html', 'value' => '<p>Contact from ' . $values['visitor']
                . ': ' . $values['email'] . "</p>\r\n"],
        ]
        && count($payload['attachments']) === 1 && $attachment['filename'] === 'attachment.txt'
        && $attachment['disposition'] === 'attachment'
        && base64_decode($attachment['content'], true) === $bytes,
        'same_submitter_corrects_form: exact Unicode reply-to subject bodies attachment payload');
    check(inventory() === $afterUpload,
        'same_submitter_corrects_form: no second FAL file; genuine returned pointer processed');
    $independentJar = new CookieJar();
    $html = request('independent_ordinary_submitter', $independentJar);
    $independent = controls($html);
    check(!str_contains($html, 'attachment.txt')
        && !str_contains(implode('', array_keys($independent['fields'])), '[__submittedFiles]')
        && $independentJar->getCookieByName('fe_typo_user')?->getValue() !== $cookie->getValue(),
        'independent_ordinary_submitter: own fresh form/cookie without prior upload preview');
    check(count(payloads()) === 1 && inventory() === $afterUpload,
        'independent_ordinary_submitter: ordinary GET preserves mail/upload inventory');
    $html = request('required_upload_absent', $independentJar, $independent, $values);
    check(errorFor(controls($html), 'attachment') && noCompletion($html),
        'required_upload_absent: native required alert without completion');
    check(count(payloads()) === 1 && inventory() === $afterUpload,
        'required_upload_absent: no inherited attachment extra payload or FAL write');
    foreach (['state_omitted_by_same_submitter', 'ordinary_mime_rejection',
        'configured_size_rejection', 'configured_size_boundary_acceptance'] as $case) {
        $ownJar = new CookieJar();
        $own = controls(request($case . '_get', $ownJar));
        $file = match ($case) {
            'ordinary_mime_rejection' => [
                'name' => 'image.png', 'mime' => 'text/plain',
                'bytes' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                    . 'AAAAC0lEQVR42mP8/x8AAwMCAO+/Zx8AAAAASUVORK5CYII='),
            ],
            'configured_size_rejection' => [
                'name' => 'oversize.txt', 'mime' => 'text/plain', 'bytes' => str_repeat('x', 1025),
            ],
            'configured_size_boundary_acceptance' => [
                'name' => 'boundary.txt', 'mime' => 'text/plain', 'bytes' => str_repeat('x', 1024),
            ],
            default => $upload,
        };
        $html = request($case, $ownJar, $own, $values, $file,
            $case === 'state_omitted_by_same_submitter');
        if ($case === 'configured_size_boundary_acceptance') {
            check(substr_count($html, 'FRONTEND-HTTP-COMPLETE') === 1,
                $case . ': exact native maximum completes once');
            $payload = payloads()[1] ?? [];
            $files = inventory()['files'];
            $last = end($files);
            check(count(payloads()) === 2 && count($files) === count($afterUpload['files']) + 1
                && $payload['attachments'][0]['filename'] === 'boundary.txt'
                && base64_decode($payload['attachments'][0]['content'], true) === $file['bytes']
                && $last['name'] === 'boundary.txt' && (int)$last['size'] === 1024
                && file_get_contents('public/fileadmin' . $last['identifier']) === $file['bytes'],
                $case . ': exactly one added API payload/FAL file preserves all1024 bytes');
        } else {
            $nativeForm = controls($html);
            check(noCompletion($html) && ($case === 'state_omitted_by_same_submitter'
                ? $nativeForm['fields'][$prefix . '[__currentPage]'] === '1'
                : errorFor($nativeForm, 'attachment')),
                $case . ': native first-page response/attachment alert without completion');
            check(count(payloads()) === 1 && inventory() === $afterUpload,
                $case . ': no additional API payload indexed file or upload folder');
        }
    }
    check(array_reduce(capture(), fn($ok, $row) => $ok && $row['finished']
        && $row['mail_restored'] && $row['guard_registered']
        && ($row['payloads'] === [] || $row['email_consumed']), true),
        'mail_fixture_restoration: every request restores MAIL and drains only owned queue');
    echo "TYPO3_FRONTEND_HTTP_COMPLETE $count checks; no provider calls\n";
} finally {
    file_put_contents('frontend-observations.json',
        json_encode($observations, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

require '/app/native/frontend-multiupload-probe.php';
