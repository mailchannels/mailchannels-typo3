<?php
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\{Bootstrap, Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$loader = require getcwd() . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
$pool = $container->get(ConnectionPool::class);
$coreIndex = getcwd() . '/vendor/typo3/cms-core/Resources/Private/Php/index.php';
$coreIndexSha = '43aefee717592d076873f67db241afbee85560c51f6380cae179c21dc2d4ec15';
if (hash_file('sha256', $coreIndex) !== $coreIndexSha) {
    throw new RuntimeException('Installed stock core HTTP entry differs from pinned source');
}
$storage = $container->get(StorageRepository::class)->getDefaultStorage();
if ($storage === null || $storage->getUid() !== 1
    || $storage->getConfiguration()['basePath'] !== 'fileadmin/'
    || $storage->getConfiguration()['pathType'] !== 'relative') {
    throw new RuntimeException('Fresh site native default storage differs from prototype mount');
}
$folder = $storage->getDefaultFolder();
$mount = $storage->getUid() . ':' . $folder->getIdentifier();
if ($mount !== '1:/user_upload/'
    || realpath(Environment::getPublicPath() . '/fileadmin' . $folder->getIdentifier())
        !== Environment::getPublicPath() . '/fileadmin/user_upload') {
    throw new RuntimeException('Converter target is not inside the disposable public site');
}
$yaml = Yaml::parseFile('/app/native/frontend-fixture.form.yaml');
$upload = $yaml['renderables'][0]['renderables'][2];
if ($upload['properties']['saveToFileMount'] !== $mount
    || $upload['properties']['allowedMimeTypes'] !== ['text/plain']
    || $upload['properties']['multiple'] !== false
    || $upload['properties']['allowRemoval'] !== false
    || $upload['validators'][1]['options']['maximum'] !== '1024B') {
    throw new RuntimeException('Native persisted upload configuration differs');
}
$setup = <<<'TS'
@import 'EXT:form/Configuration/TypoScript/setup.typoscript'
plugin.tx_form.settings {
  persistenceIdentifier = EXT:mailchannels_email_api_candidate/native/frontend-fixture.form.yaml
  yamlSettingsOverrides.persistenceManager.allowedExtensionPaths {
    100 = EXT:mailchannels_email_api_candidate/native/
  }
}
config.disableAllHeaderCode = 1
page = PAGE
page.10 = CONTENT
page.10 {
  table = tt_content
  select.orderBy = sorting
  select.where = CType='form_formframework'
}
tt_content = CASE
tt_content {
  key.field = CType
  form_formframework = EXTBASEPLUGIN
  form_formframework {
    extensionName = Form
    pluginName = Formframework
  }
}
TS;
$pool->getConnectionForTable('pages')->update('pages', ['hidden' => 0], ['uid' => 1]);
$template = $pool->getConnectionForTable('sys_template');
$template->insert('sys_template', [
    'pid' => 1, 'title' => 'Frontend HTTP fixture', 'root' => 1, 'clear' => 3,
    'config' => $setup,
]);
$templateUid = (int)$template->lastInsertId();
$content = $pool->getConnectionForTable('tt_content');
$content->insert('tt_content', [
    'pid' => 1, 'CType' => 'form_formframework', 'header' => 'Frontend HTTP fixture',
    'colPos' => 0, 'sorting' => 1,
]);
$contentUid = (int)$content->lastInsertId();
$configFile = getcwd() . '/config/sites/main/config.yaml';
$config = Yaml::parseFile($configFile);
$config['base'] = 'http://127.0.0.1:8080/';
file_put_contents($configFile, Yaml::dump($config, 6));
GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
$stacks = [];
foreach (['core', 'frontend'] as $stack) {
    $stacks[$stack] = array_reverse(
        $container->get(MiddlewareStackResolver::class)->resolve($stack)->getArrayCopy(), true
    );
}
$schema = [];
foreach (['pages', 'sys_template', 'tt_content', 'sys_file_storage', 'sys_file',
    'fe_sessions'] as $table) {
    $schema[$table] = $pool->getConnectionForTable($table)
        ->executeQuery('PRAGMA table_info(' . $table . ')')->fetchAllAssociative();
}
$sourceFiles = [
    'typo3/cms-core/Resources/Private/Php/index.php',
    'typo3/cms-core/Classes/Http/Application.php',
    'typo3/cms-core/Classes/Http/AbstractApplication.php',
    'typo3/cms-core/Classes/Http/RequestHandler.php',
    'typo3/cms-core/Classes/Http/ServerRequestFactory.php',
    'typo3/cms-core/Classes/Http/MiddlewareStackResolver.php',
    'typo3/cms-core/Classes/Http/MiddlewareDispatcher.php',
    'typo3/cms-core/Classes/Resource/StorageRepository.php',
    'typo3/cms-core/Classes/EventDispatcher/ListenerProvider.php',
    'typo3/cms-core/Classes/Mail/Mailer.php',
    'typo3/cms-core/Classes/Mail/FluidEmail.php',
    'typo3/cms-core/Classes/Session/UserSessionManager.php',
    'typo3/cms-core/Classes/Session/UserSession.php',
    'typo3/cms-core/Classes/Session/SessionManager.php',
    'typo3/cms-core/Classes/Session/Backend/DatabaseSessionBackend.php',
    'typo3/cms-core/Classes/Authentication/AbstractUserAuthentication.php',
    'typo3/cms-core/Classes/Http/SetCookieService.php',
    'typo3/cms-frontend/Classes/Authentication/FrontendUserAuthentication.php',
    'typo3/cms-core/Configuration/RequestMiddlewares.php',
    'typo3/cms-core/ext_tables.sql',
    'typo3/cms-frontend/Classes/Http/Application.php',
    'typo3/cms-frontend/Classes/Http/RequestHandler.php',
    'typo3/cms-frontend/Classes/ContentObject/ContentContentObject.php',
    'typo3/cms-frontend/Classes/ContentObject/CaseContentObject.php',
    'typo3/cms-frontend/Configuration/RequestMiddlewares.php',
    'typo3/cms-frontend/ext_tables.sql',
    'typo3/cms-extbase/Classes/ContentObject/ExtbasePluginContentObject.php',
    'typo3/cms-form/Classes/Domain/Runtime/FormRuntime.php',
    'typo3/cms-form/Classes/Domain/Runtime/FormRuntime/FormSession.php',
    'typo3/cms-form/Classes/Domain/Factory/ArrayFormFactory.php',
    'typo3/cms-form/Classes/Domain/Model/FormElements/FileUpload.php',
    'typo3/cms-form/Classes/Mvc/Property/PropertyMappingConfiguration.php',
    'typo3/cms-form/Classes/Mvc/Property/TypeConverter/UploadedFileReferenceConverter.php',
    'typo3/cms-form/Classes/Mvc/Property/TypeConverter/PseudoFile.php',
    'typo3/cms-form/Classes/Mvc/Validation/FileSizeValidator.php',
    'typo3/cms-form/Classes/Mvc/Validation/MimeTypeValidator.php',
    'typo3/cms-form/Classes/ViewHelpers/Form/UploadedResourceViewHelper.php',
    'typo3/cms-form/Classes/ViewHelpers/RenderFormValueViewHelper.php',
    'typo3/cms-form/Classes/ViewHelpers/FormViewHelper.php',
    'typo3/cms-form/Classes/Domain/Finishers/EmailFinisher.php',
    'typo3/cms-form/ext_localconf.php',
    'typo3/cms-form/Configuration/TypoScript/setup.typoscript',
    'typo3/cms-form/Configuration/Form/Base/FormElements/FileUpload.yaml',
    'typo3/cms-form/Configuration/Form/Base/Validators/FileSize.yaml',
    'symfony/mime/Encoder/QpContentEncoder.php',
];
foreach (array_merge(...array_values($stacks)) as $class) {
    $sourceFiles[] = substr((new ReflectionClass($class))->getFileName(),
        strlen(getcwd() . '/vendor/'));
}
$sources = [];
foreach (array_unique($sourceFiles) as $path) {
    $sources[$path] = hash_file('sha256', getcwd() . '/vendor/' . $path);
}
$manifest = [
    'source_sha256' => $sources,
    'php_version' => PHP_VERSION,
    'sqlite_version' => $pool->getConnectionForTable('pages')
        ->executeQuery('SELECT sqlite_version()')->fetchOne(),
    'installed_packages' => array_map(fn($package) => array_intersect_key($package,
        array_flip(['name', 'version', 'source'])),
        json_decode(file_get_contents('vendor/composer/installed.json'), true, 512,
            JSON_THROW_ON_ERROR)['packages']),
    'page_uid' => 1, 'template_uid' => $templateUid, 'content_uid' => $contentUid,
    'mount' => $mount, 'storage_configuration' => $storage->getConfiguration(),
    'middlewares_in_execution_order' => $stacks, 'schema' => $schema,
    'fixture_sha256' => hash_file('sha256', '/app/native/frontend-fixture.form.yaml'),
    'typoscript' => $setup,
];
file_put_contents(getcwd() . '/frontend-setup.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
file_put_contents(getcwd() . '/frontend-entry-origin.json', json_encode([
    'symlink_target' => is_link(getcwd() . '/public/index.php')
        ? readlink(getcwd() . '/public/index.php') : null,
    'sha256' => hash_file('sha256', getcwd() . '/public/index.php'),
], JSON_THROW_ON_ERROR) . "\n");
copy(getcwd() . '/public/index.php', getcwd() . '/frontend-original-index.php');
unlink(getcwd() . '/public/index.php');
copy('/app/native/frontend-entry.php', getcwd() . '/public/index.php');
if (hash_file('sha256', $coreIndex) !== $coreIndexSha) {
    throw new RuntimeException('Owned public entry replacement mutated installed core entry');
}
echo "TYPO3_FRONTEND_SETUP_COMPLETE native PAGE/CONTENT/Form plugin; mount=$mount\n";
