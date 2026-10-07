<?php
require __DIR__.'/vendor/autoload.php';
use MailChannels\Typo3\Mail\{ApiTransport,ConfigurationGuard,TransportFailure};
use Symfony\Component\DependencyInjection\{ContainerBuilder,Reference};
use Symfony\Component\DependencyInjection\Loader\{PhpFileLoader,YamlFileLoader};
use Symfony\Component\Config\FileLocator;
use TYPO3\CMS\Core\EventDispatcher\{ListenerProvider,EventDispatcher};
use TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent;
use TYPO3\CMS\Core\Mail\Mailer;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Email;
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$installed=\Composer\InstalledVersions::getInstallPath('mailchannels/typo3-email-api-candidate');
check(is_dir($installed),'local Composer package installed');
check(class_exists(ApiTransport::class) && class_exists(ConfigurationGuard::class),'candidate PSR-4 autoload resolves without manual includes');
$metadata=json_decode(file_get_contents($installed.'/composer.json'),true,512,JSON_THROW_ON_ERROR);
check($metadata['type']==='typo3-cms-extension' && $metadata['extra']['typo3/cms']['extension-key']==='mailchannels_email_api_candidate','TYPO3 package identity present and provisional');
$_EXTKEY='mailchannels_email_api_candidate';require $installed.'/ext_emconf.php';
check($EM_CONF[$_EXTKEY]['state']==='alpha' && $EM_CONF[$_EXTKEY]['version']==='0.0.0','legacy metadata explicitly unreleased alpha');
check($EM_CONF[$_EXTKEY]['constraints']['depends']['typo3']==='14.3.7-14.3.7' && $metadata['require']['typo3/cms-core']==='14.3.7','metadata core constraints agree');
$container=new ContainerBuilder();
// Load TYPO3's actual attribute registrations/compiler passes, not a copied callback.
$core=\Composer\InstalledVersions::getInstallPath('typo3/cms-core');
(new PhpFileLoader($container,new FileLocator($core.'/Configuration')))->load('Services.php');
$container->register(ListenerProvider::class)->setArguments([new Reference('service_container')])->setPublic(true);
$container->register(EventDispatcher::class)->setArguments([new Reference(ListenerProvider::class)])->setPublic(true);
(new YamlFileLoader($container,new FileLocator($installed.'/Configuration')))->load('Services.yaml');
// This isolated fixture compiles event services, not all CMS subsystems.
$passes=$container->getCompilerPassConfig();
$passes->setBeforeOptimizationPasses(array_values(array_filter($passes->getBeforeOptimizationPasses(),static fn($pass)=>!str_starts_with($pass::class,'TYPO3\\CMS\\Core\\DependencyInjection\\') || $pass instanceof \TYPO3\CMS\Core\DependencyInjection\ListenerProviderPass)));
$container->compile();
$provider=$container->get(ListenerProvider::class);
$definitions=$provider->getAllListenerDefinitions();
check(isset($definitions[BeforeMailerSentMessageEvent::class]['mailchannels/direct-transport-configuration']),'native compiler discovers and registers guard attribute');
check($container->get(ConfigurationGuard::class) instanceof ConfigurationGuard,'compiled guard service resolves');
$events=$container->get(EventDispatcher::class);
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=['transport'=>'null'];
$message=(new Email())->from('sender@example.com')->to('recipient@example.com')->text('Synthetic');
$mailer=new Mailer(new NullTransport(),$events);$mailer->send($message);
check($mailer->getSentMessage()!==null,'compiled listener preserves unrelated null mail');
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=['transport'=>ApiTransport::class,'dsn'=>'null://null'];
$mailer=new Mailer(new NullTransport(),$events);$bad=false;
try{$mailer->send($message);}catch(TransportFailure $e){$bad=$e->outcome==='configuration_invalid';}
check($bad,'compiled listener rejects API selection bypass before send');
check(!file_exists($installed.'/ext_localconf.php'),'package installation has no automatic routing activation hook');
echo "TYPO3_PACKAGE_COMPLETE $count checks; no provider calls\n";
