<?php
$loader=require getcwd().'/vendor/autoload.php';
use TYPO3\CMS\Core\Core\{SystemEnvironmentBuilder,Bootstrap,Environment};
use TYPO3\CMS\Core\Mail\{Mailer,MailerInterface,FluidEmail};
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent;
use MailChannels\Typo3\Mail\{ApiTransport,ConfigurationGuard,TransportFailure};
SystemEnvironmentBuilder::run(1,SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container=Bootstrap::init($loader);
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
check(Environment::getProjectPath()===getcwd(),'full bootstrap uses isolated project');
check($container->get(\TYPO3\CMS\Core\Package\PackageManager::class)->isPackageActive('mailchannels_email_api_candidate'),'candidate extension active in installed package manager');
$db=$container->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('be_users');
check((int)$db->count('*','be_users',['username'=>'fixture-admin'])===1,'installed database contains synthetic administrator');
$provider=$container->get(ListenerProvider::class);$definitions=$provider->getAllListenerDefinitions();
check(isset($definitions[BeforeMailerSentMessageEvent::class]['mailchannels/direct-transport-configuration']),'full site container automatically registered guard');
check($container->get(ConfigurationGuard::class) instanceof ConfigurationGuard,'full site guard service resolves');
$original=$GLOBALS['TYPO3_CONF_VARS']['MAIL'];
check($original['transport']==='null','installation preserves disabled mail');
$events=$container->get(\Psr\EventDispatcher\EventDispatcherInterface::class);
try {
 $m=(new \Symfony\Component\Mime\Email())->from('sender@example.com')->to('recipient@example.com')->text('Synthetic');
 $mailer=$container->get(MailerInterface::class);$mailer->send($m);check($mailer->getSentMessage()!==null,'container Mailer sends through null without network');
 $GLOBALS['TYPO3_CONF_VARS']['MAIL']=array_replace($original,['transport'=>ApiTransport::class,'dsn'=>'null://null']);
 $bad=false;try{(new Mailer(null,$events))->send($m);}catch(TransportFailure $e){$bad=$e->outcome==='configuration_invalid';}
 check($bad,'installed native factory DSN bypass rejected by automatic listener');
 $settings=array_replace($original,['transport'=>ApiTransport::class,'dsn'=>'','transport_spool_type'=>'','mailchannels_api_key'=>'synthetic-only','mailchannels_allowed_senders'=>['sender@example.com']]);
 $GLOBALS['TYPO3_CONF_VARS']['MAIL']=$settings;
 $payloads=[];$client=new \GuzzleHttp\Client(['handler'=>function($request,$options)use(&$payloads){$payloads[]=json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(202,[],json_encode(['results'=>[['index'=>0,'status'=>'sent']]])));}]);
 $transport=new ApiTransport($settings,$client);$mailer=new Mailer($transport,$events);
 $paths=new \TYPO3\CMS\Fluid\View\TemplatePaths();$paths->setTemplateRootPaths(['/app/native/templates/']);
 $fluid=(new FluidEmail($paths))->from('sender@example.com')->to('recipient@example.com')->subject('Initial')->format(FluidEmail::FORMAT_BOTH)->setTemplate('Fixture')->assign('label','Native ✓');
 $mailer->send($fluid);
 check(count($payloads)===1,'installed FluidEmail reaches candidate once');
 check(count($payloads[0]['content'])===2 && $payloads[0]['content'][0]['type']==='text/plain' && $payloads[0]['content'][1]['type']==='text/html','Fluid plain and HTML alternatives preserved');
 check(str_contains($payloads[0]['content'][0]['value'],'Hello Native ✓') && str_contains($payloads[0]['content'][1]['value'],'<p>Hello Native ✓</p>'),'native Fluid variable rendering preserved');
 check($payloads[0]['headers']['X-Mailer']==='TYPO3','full native mailer enrichment preserved');
 check($payloads[0]['subject']==='Fixture Native ✓','Fluid subject generated before payload construction');
} finally {$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$original;}
check($GLOBALS['TYPO3_CONF_VARS']['MAIL']===$original,'synthetic runtime mail settings restored');
echo "TYPO3_NATIVE_COMPLETE $count checks; no provider calls\n";
