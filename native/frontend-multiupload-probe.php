<?php
use GuzzleHttp\Cookie\CookieJar;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Form\Security\HashScope;

$mode = getenv('TYPO3_TEST_CORE_DISPLAY_FIX') === '1' ? 'proposed-core-fix' : 'stock';
$pool = $container->get(ConnectionPool::class);
$template = $pool->getConnectionForTable('sys_template');
$beforeTemplate = $template->select(['uid', 'config'], 'sys_template',
    ['uid' => $manifest['template_uid']])->fetchAssociative();
$oldFixture = 'native/frontend-fixture.form.yaml';
$newFixture = 'native/frontend-multiupload-fixture.form.yaml';
if ($beforeTemplate === false || $beforeTemplate['config'] !== $manifest['typoscript']
    || substr_count($beforeTemplate['config'], $oldFixture) !== 1) {
    throw new RuntimeException('Owned original template identity/configuration changed');
}
$setup = str_replace($oldFixture, $newFixture, $beforeTemplate['config']);
$template->update('sys_template', ['config' => $setup], ['uid' => $manifest['template_uid']]);
$afterTemplate = $template->select(['uid', 'config'], 'sys_template',
    ['uid' => $manifest['template_uid']])->fetchAssociative();
if ($afterTemplate['config'] !== $setup) {
    throw new RuntimeException('Owned multiupload template configuration was not persisted');
}
GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
$yaml = \Symfony\Component\Yaml\Yaml::parseFile('/app/' . $newFixture);
$uploadProperties = $yaml['renderables'][0]['renderables'][2];
if ($yaml['identifier'] !== 'frontendmultiuploadfixture'
    || $uploadProperties['properties'] !== [
        'saveToFileMount' => '1:/user_upload/', 'multiple' => true, 'allowRemoval' => false,
        'allowedMimeTypes' => ['text/plain'],
    ] || $uploadProperties['validators'] !== [
        ['identifier' => 'NotEmpty'],
        ['identifier' => 'FileSize', 'options' => ['maximum' => '1024B']],
    ] || $yaml['finishers'][0]['options']['templateName'] !== 'Default'
    || $yaml['finishers'][0]['options']['templateRootPaths'] !== [
        100 => 'EXT:form/Resources/Private/Frontend/Templates/Finishers/Email/',
    ] || $yaml['finishers'][0]['options']['attachUploads'] !== true) {
    throw new RuntimeException('Multiple upload fixture differs from pinned standard boundaries');
}

$authorities = [
    'typo3/cms-form/Classes/Domain/Model/FormElements/FileUpload.php'
        => '0ebd38338f96125b3c01f8cd4249f186fccfe5c0ae2a14f71e2e012090b5496a',
    'typo3/cms-form/Classes/Mvc/Property/PropertyMappingConfiguration.php'
        => '1b69142202717fa0e6bd09153edce5ac37de2512f29389d5dd9199e4f4309b71',
    'typo3/cms-form/Classes/Mvc/Property/TypeConverter/UploadedFileReferenceConverter.php'
        => 'c05e550007e3fbc86cb64c03cf965c9779afc36cf1c3a81ba230497c0455965a',
    'typo3/cms-form/Classes/ViewHelpers/Form/UploadedResourceViewHelper.php'
        => 'fbffba6b460b255267bd0a365501b6bcdbeb4fc85780cf9ddd53a85b466cb00f',
    'typo3/cms-form/Classes/ViewHelpers/RenderFormValueViewHelper.php'
        => 'f3535bab9f5b705f1acc12be98277f16b01be2fef9803ae08cede3ce036830b6',
    'typo3/cms-form/Classes/Domain/Finishers/EmailFinisher.php'
        => 'f7633912f82c7442bbd4eafc0a8a0be0bc510c4b1e8cf360ef3c18e49653c674',
    'typo3/cms-form/Classes/Domain/Finishers/AbstractFinisher.php'
        => 'd5777f0fb4c1d4d85928f2ff3a2dab76d9f32b0b24897ecd1814554fefe0d294',
    'typo3/cms-form/Classes/Service/FormValueResolver.php'
        => 'd7a1bad120a7e579024ae6045270fe3d2aa6d93ffbb282f466c8939f81470ad1',
    'typo3/cms-form/Classes/Security/HashScope.php'
        => '214049913c59d1134637325e19c4fdcfac439fa0ec5c2c844743dcacdadf8392',
    'typo3/cms-form/Resources/Private/Frontend/Partials/FileUpload.fluid.html'
        => '009d2d53e38632bd111e5fc249edc9fa6aa93e4748bc4d563e7114d58b82c447',
    'typo3/cms-form/Resources/Private/Frontend/Templates/Finishers/Email/Default.fluid.html'
        => '3d9d0c8fc4ad941142fc42f857fe8383a41061db7c62086d7d6d85f0b2865bcc',
    'typo3/cms-form/Resources/Private/Frontend/Templates/Finishers/Email/Default.fluid.txt'
        => '4c22116eb017b25342656bc56d0cd33098b725fc00d9311c1c2f2d8b3078c87e',
    'typo3/cms-core/Classes/Crypto/HashService.php'
        => 'fc1693ae1280c29a4c80f1bbaa7b5df5c5e20740d07affa4b46afa1e5e82891c',
];
$sourceSnapshots = [];
foreach ($authorities as $path => $expected) {
    if ($mode === 'proposed-core-fix' && str_ends_with($path, 'RenderFormValueViewHelper.php')) {
        $expected = 'af30d5e97b983e5e9926f52beb413bc62695a29e31c3cc48e6eaeeccc5d450b1';
    }
    $bytes = file_get_contents('vendor/' . $path);
    if (hash('sha256', $bytes) !== $expected) {
        throw new RuntimeException('Fresh installed primary source differs: ' . $path);
    }
    $sourceSnapshots[$path] = ['sha256' => $expected, 'bytes' => $bytes];
}
$locked = json_decode(file_get_contents('composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$installed = json_decode(file_get_contents('vendor/composer/installed.json'), true, 512,
    JSON_THROW_ON_ERROR)['packages'];
$identities = fn($rows) => array_column(array_map(fn($row) => array_intersect_key($row,
    array_flip(['name', 'version', 'source'])), $rows), null, 'name');
if (count($installed) !== 92 || $identities($installed) !== $identities($locked['packages'])) {
    throw new RuntimeException('Fresh installed package identities differ from locked92 packages');
}
file_put_contents('frontend-multiupload-setup.json', json_encode([
    'mode' => $mode, 'template_before' => $beforeTemplate, 'template_after' => $afterTemplate,
    'content_uid' => $manifest['content_uid'], 'fixture' => $yaml,
    'fixture_sha256' => hash_file('sha256', '/app/' . $newFixture),
    'source_snapshots' => $sourceSnapshots, 'installed_packages' => $identities($installed),
    'php_version' => PHP_VERSION,
    'typo3_version' => (new \TYPO3\CMS\Core\Information\Typo3Version())->getVersion(),
    'sqlite_version' => $manifest['sqlite_version'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

$previousCaptures = capture();
$namespace = 'frontendmultiuploadfixture-' . $manifest['content_uid'];
$prefix = 'tx_form_formframework[' . $namespace . ']';
$count = 0;
$observations = [];
function multiNoCompletion(string $html): bool
{
    return !str_contains($html, 'FRONTEND-MULTIUPLOAD-COMPLETE')
        && !str_contains($html, 'FRONTEND-MULTIUPLOAD-DELIVERY-FAILED');
}
function pointers(array $form): array
{
    return array_filter($form['fields'], fn($name) => str_contains($name, '[__submittedFiles]'),
        ARRAY_FILTER_USE_KEY);
}
function diskFiles(array $inventory): array
{
    return array_column(array_map(fn($row) => ['uid' => $row['uid'],
        'bytes_base64' => base64_encode(
            file_get_contents('public/fileadmin' . $row['identifier']))],
        $inventory['files']), 'bytes_base64', 'uid');
}
function multiObservation(string $case, ?array $form, array $values, array $uploads,
    ?array $returned): void
{
    global $observations, $prefix;
    $fields = $form['fields'] ?? [];
    foreach ($values as $name => $value) {
        $fields[$prefix . '[' . $name . ']'] = $value;
    }
    $observations[$case]['sent_fields'] = $fields;
    $observations[$case]['returned_fields'] = $returned['fields'] ?? [];
    $observations[$case]['upload_parts'] = array_map(fn($file) => [
        'field_name' => $form['file'], 'name' => $file['name'], 'mime' => $file['mime'],
        'size' => strlen($file['bytes']), 'sha256' => hash('sha256', $file['bytes']),
    ], $uploads);
    $observations[$case]['disk_files'] = diskFiles(inventory());
}
try {
    $initialInventory = inventory();
    $initialDisk = diskFiles($initialInventory);
    $initialPayloads = count(payloads());
    $jar = new CookieJar();
    $case = 'multiupload_fresh_get';
    $html = request($case, $jar);
    $fresh = controls($html);
    multiObservation($case, null, [], [], $fresh);
    parse_str(parse_url($fresh['action'], PHP_URL_QUERY) ?? '', $query);
    check($fresh['file'] === $prefix . '[attachment][]'
        && $fresh['xpath']->query('//input[@type="file" and @multiple]')->length === 1
        && ($fresh['fields'][$prefix . '[__currentPage]'] ?? null) === '1'
        && ($fresh['fields'][$prefix . '[__state]'] ?? '') !== ''
        && !isset($fresh['fields'][$prefix . '[__session]'])
        && ($query['tx_form_formframework']['action'] ?? '') === 'perform',
        'M01 multiupload_fresh_get: stock multiple action state current-page; no session');
    check(multiNoCompletion($html) && count(payloads()) === $initialPayloads
        && inventory() === $initialInventory && diskFiles(inventory()) === $initialDisk,
        'M02 multiupload_fresh_get: no completion failure payload or FAL delta');
    $values = ['visitor' => 'Zoë 東京 ✓', 'email' => 'zoe@example.com'];
    $case = 'multiupload_required_absent';
    $html = request($case, $jar, $fresh, $values);
    $absent = controls($html);
    multiObservation($case, $fresh, $values, [], $absent);
    check(errorFor($absent, 'attachment') && multiNoCompletion($html),
        'M03 multiupload_required_absent: native required alert; no completion or failure');
    check(count(payloads()) === $initialPayloads && inventory() === $initialInventory
        && diskFiles(inventory()) === $initialDisk,
        'M04 multiupload_required_absent: no payload file folder or prior-byte delta');
    $uploads = [
        ['name' => 'first.txt', 'mime' => 'text/plain',
            'bytes' => "First native multipart upload\nZoë 東京 ✓\n"],
        ['name' => 'second.txt', 'mime' => 'text/plain',
            'bytes' => "Second native multipart upload\r\nSecond line\r\n"],
    ];
    $values['email'] = 'invalid-email';
    $case = 'multiupload_validation_two_uploads';
    $html = request($case, $jar, $absent, $values, $uploads);
    $returned = controls($html);
    multiObservation($case, $absent, $values, $uploads, $returned);
    check(errorFor($returned, 'email') && multiNoCompletion($html)
        && count(payloads()) === $initialPayloads,
        'M05 multiupload_validation_two_uploads: repeated multipart name; email alert no API');
    check(($returned['fields'][$prefix . '[__state]'] ?? '') !== ''
        && ($returned['fields'][$prefix . '[__session]'] ?? '') !== ''
        && ($returned['fields'][$prefix . '[__currentPage]'] ?? '') === '1'
        && $returned['fields'][$prefix . '[visitor]'] === $values['visitor']
        && $returned['fields'][$prefix . '[email]'] === $values['email'],
        'M06 multiupload_validation_two_uploads: genuine own state session and submitted values');
    $afterUpload = inventory();
    $newFiles = array_values(array_udiff($afterUpload['files'], $initialInventory['files'],
        fn($a, $b) => $a['uid'] <=> $b['uid']));
    $newFolders = array_values(array_diff($afterUpload['folders'], $initialInventory['folders']));
    $pointerFields = pointers($returned);
    $pointerBodies = array_map(fn($value) => $container->get(HashService::class)
        ->validateAndStripHmac($value, HashScope::ResourcePointer->prefix()), $pointerFields);
    $pointerNamesValid = true;
    foreach (array_keys($pointerFields) as $name) {
        $pointerNamesValid = $pointerNamesValid && preg_match('/^' . preg_quote($prefix, '/')
            . '\\[attachment\\]\\[__submittedFiles\\]\\[\\d+\\]'
            . '\\[submittedFile\\]\\[resourcePointer\\]$/D', $name) === 1;
    }
    $expectedPointerBodies = array_map(fn($row) => 'file:' . $row['uid'], $newFiles);
    $previews = [];
    foreach ($returned['xpath']->query('//span[@class="form-element-fileupload-name"]') as $node) {
        $previews[] = $node->textContent;
    }
    $observations[$case]['validated_pointer_bodies'] = $pointerBodies;
    check(count($pointerFields) === 2 && $pointerNamesValid
        && array_values($pointerBodies) === $expectedPointerBodies
        && $previews === array_column($uploads, 'name')
        && $returned['xpath']->query('//input[@type="checkbox"]')->length === 0,
        'M07 multiupload_validation_two_uploads: ordered signed UID pointers previews; no removal');
    $filesExact = count($newFiles) === 2 && count($newFolders) === 1;
    foreach ($newFiles as $index => $row) {
        $file = $uploads[$index];
        $filesExact = $filesExact && $row['name'] === $file['name']
            && (int)$row['storage'] === 1 && (int)$row['size'] === strlen($file['bytes'])
            && $row['sha1'] === sha1($file['bytes'])
            && dirname('public/fileadmin' . $row['identifier']) === $newFolders[0]
            && file_get_contents('public/fileadmin' . $row['identifier']) === $file['bytes'];
    }
    $retainedDisk = diskFiles($afterUpload);
    check($filesExact && array_slice($afterUpload['files'], 0,
        count($initialInventory['files'])) === $initialInventory['files']
        && array_intersect_key(diskFiles($afterUpload), $initialDisk) === $initialDisk,
        'M08 multiupload_validation_two_uploads: exact FAL rows sizes hashes bytes; old intact');
    $cookie = $jar->getCookieByName('fe_typo_user');
    $uploadCapture = $observations[$case]['capture'];
    check($cookie !== null && $cookie->getValue() !== ''
        && $uploadCapture['anonymous_session_persisted'],
        'M09 multiupload_validation_two_uploads: actual cookie native anonymous session persisted');
    $case = 'multiupload_same_submitter_invalid_again';
    $html = request($case, $jar, $returned);
    $again = controls($html);
    multiObservation($case, $returned, [], [], $again);
    check(errorFor($again, 'email') && multiNoCompletion($html)
        && $again['fields'][$prefix . '[visitor]'] === $values['visitor']
        && $again['fields'][$prefix . '[email]'] === $values['email']
        && $again['fields'][$prefix . '[__session]']
            === $returned['fields'][$prefix . '[__session]']
        && pointers($again) === $pointerFields,
        'M10 multiupload_same_submitter_invalid_again: own values pointers session retained');
    $againCapture = $observations[$case]['capture'];
    check($againCapture['request_cookie_sha256'] === hash('sha256', $cookie->getValue())
        && $againCapture['session_identifier_sha256']
            === $uploadCapture['session_identifier_sha256']
        && $againCapture['anonymous_session_persisted'],
        'M11 multiupload_same_submitter_invalid_again: actual cookie same persisted session');
    check(count(payloads()) === $initialPayloads && inventory() === $afterUpload
        && diskFiles(inventory()) === $retainedDisk,
        'M12 multiupload_same_submitter_invalid_again: no upload payload row folder or byte delta');
    $case = 'multiupload_same_submitter_corrects_email';
    $html = request($case, $jar, $again, ['email' => 'zoe@example.com']);
    multiObservation($case, $again, ['email' => 'zoe@example.com'], [], null);
    $correction = $observations[$case]['capture'];
    $sent = $again['fields'];
    $sent[$prefix . '[email]'] = 'zoe@example.com';
    check($observations[$case]['sent_fields'] === $sent
        && $correction['request_cookie_sha256'] === hash('sha256', $cookie->getValue())
        && $correction['session_identifier_sha256'] === $uploadCapture['session_identifier_sha256']
        && $correction['anonymous_session_persisted'],
        'M13 multiupload_same_submitter_corrects_email: own controls email-only; same session');
    if ($mode === 'stock') {
        $expectedDiagnostics = [['level' => 'error', 'exception_chain' => [
            ['class' => \TYPO3\CMS\Form\Domain\Finishers\Exception\FinisherException::class,
                'code' => 1754047320],
            ['class' => \MailChannels\Typo3\Mail\TransportFailure::class,
                'code' => 0, 'outcome' => 'message_unsupported'],
        ]]];
        $outcomeExact = substr_count($html, 'FRONTEND-MULTIUPLOAD-DELIVERY-FAILED') === 1
            && !str_contains($html, 'FRONTEND-MULTIUPLOAD-COMPLETE')
            && count(payloads()) === $initialPayloads
            && $correction['finisher_diagnostics'] === $expectedDiagnostics;
    } else {
        $payload = payloads()[$initialPayloads] ?? [];
        $attachmentsExact = count($payload['attachments'] ?? []) === 2;
        foreach ($payload['attachments'] ?? [] as $index => $attachment) {
            $bytes = base64_decode($attachment['content'], true);
            $attachmentsExact = $attachmentsExact
                && $attachment['filename'] === $uploads[$index]['name']
                && $attachment['type'] === 'text/plain'
                && $attachment['disposition'] === 'attachment'
                && $bytes === $uploads[$index]['bytes'];
            $observations[$case]['decoded_attachments'][] = [
                'name' => $attachment['filename'], 'bytes' => $bytes,
                'sha256' => hash('sha256', $bytes),
            ];
        }
        $bodiesExact = array_column($payload['content'] ?? [], 'type')
            === ['text/plain', 'text/html'];
        $expectedFields = [
            'Visitor' => 'Zoë 東京 ✓', 'Email' => 'zoe@example.com',
            'Attachment' => 'first.txt, second.txt',
        ];
        $plainLines = explode("\r\n", $payload['content'][0]['value'] ?? '');
        $mailDom = new DOMDocument();
        $mailDom->loadHTML('<?xml encoding="UTF-8">' . ($payload['content'][1]['value'] ?? ''),
            LIBXML_NOERROR | LIBXML_NOWARNING);
        $mailXpath = new DOMXPath($mailDom);
        $actualFields = [];
        foreach ($expectedFields as $label => $value) {
            $bodiesExact = $bodiesExact
                && count(array_keys($plainLines, $label . ': ' . $value, true)) === 1;
            $cells = $mailXpath->query('//tr[td[1][normalize-space(.)="' . $label . '"]]/td[2]');
            $actualFields[$label] = $cells->length === 1
                ? preg_replace('/\s+/u', ' ', trim($cells->item(0)->textContent)) : null;
        }
        $bodiesExact = $bodiesExact && $actualFields === $expectedFields;
        $observations[$case]['html_fields'] = $actualFields;
        $outcomeExact = substr_count($html, 'FRONTEND-MULTIUPLOAD-COMPLETE') === 1
            && !str_contains($html, 'FRONTEND-MULTIUPLOAD-DELIVERY-FAILED')
            && count(payloads()) === $initialPayloads + 1
            && $correction['finisher_diagnostics'] === []
            && $payload['subject'] === 'Contact Zoë 東京 ✓'
            && $payload['from'] === ['email' => 'sender@example.com']
            && $payload['personalizations'] === [
                ['to' => [['email' => 'recipient@example.com', 'name' => 'Recipient']]],
            ] && $payload['reply_to'] === ['email' => 'zoe@example.com', 'name' => 'Zoë 東京 ✓']
            && $attachmentsExact && $bodiesExact;
    }
    check($outcomeExact,
        'M14 multiupload_same_submitter_corrects_email: exact ' . $mode . ' native mail outcome');
    check(inventory() === $afterUpload
        && diskFiles(inventory()) === $retainedDisk,
        'M15 multiupload_same_submitter_corrects_email: same FAL UIDs rows folder bytes');
    $captures = capture();
    $newCaptures = array_slice($captures, count($previousCaptures));
    check(count($newCaptures) === 5
        && array_slice($captures, 0, count($previousCaptures)) === $previousCaptures
        && array_reduce($newCaptures, fn($ok, $row) => $ok && $row['finished']
            && $row['mail_restored'] && $row['guard_registered']
            && ($row['payloads'] === [] || $row['email_consumed']), true),
        'M16 multiupload_fixture_restoration: five restored captures; prior prefix unchanged');
    echo "TYPO3_FRONTEND_MULTIUPLOAD_COMPLETE $count checks; mode=$mode; no provider calls\n";
} finally {
    file_put_contents('frontend-multiupload-observations.json',
        json_encode($observations, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
