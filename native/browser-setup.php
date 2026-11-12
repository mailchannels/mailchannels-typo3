<?php
// Points the disposable site's form page at the browser fixture (stock core, the extension's
// opt-in email templates, multiple uploads). Runs in the HTTP container after the HTTP probes.
use TYPO3\CMS\Core\Core\{Bootstrap, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Database\ConnectionPool;

$loader = require getcwd() . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
$manifest = json_decode(file_get_contents('frontend-setup.json'), true, 512, JSON_THROW_ON_ERROR);
$template = $container->get(ConnectionPool::class)->getConnectionForTable('sys_template');
$row = $template->select(['config'], 'sys_template', ['uid' => $manifest['template_uid']])->fetchAssociative();
$current = 'native/frontend-multiupload-fixture.form.yaml';
if ($row === false || substr_count($row['config'], $current) !== 1) {
    throw new RuntimeException('Owned template does not reference the multiupload fixture once');
}
$setup = str_replace($current, 'native/frontend-browser-fixture.form.yaml', $row['config']);
$template->update('sys_template', ['config' => $setup], ['uid' => $manifest['template_uid']]);
$container->get(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
echo 'TYPO3_BROWSER_SETUP_COMPLETE namespace=frontendbrowserfixture-' . $manifest['content_uid'] . "\n";
