<?php
require __DIR__.'/vendor/autoload.php';
foreach(['EnvelopeMapper','BodyMapper','PayloadBuilder','TransportFailure','ApiTransport','ConfigurationGuard'] as $class)require __DIR__.'/../Classes/Mail/'.$class.'.php';
use MailChannels\Typo3\Mail\{ApiTransport,TransportFailure,ConfigurationGuard};
use TYPO3\CMS\Core\Mail\{Mailer,MailerInterface,TransportFactory};
use TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent;
use TYPO3\CMS\Core\EventDispatcher\{ListenerProvider,EventDispatcher};
use Symfony\Component\Mailer\Transport\{NullTransport,TransportInterface};
use Symfony\Component\Mime\Email;
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function message(){return (new Email())->from('sender@example.com')->to('to@example.com')->text('Synthetic');}
$container=new \Symfony\Component\DependencyInjection\Container();$container->set(ConfigurationGuard::class,new ConfigurationGuard());
$provider=new ListenerProvider($container);$provider->addListener(BeforeMailerSentMessageEvent::class,ConfigurationGuard::class);
$events=new EventDispatcher($provider);
$settings=['transport'=>ApiTransport::class,'dsn'=>'','transport_spool_type'=>'','mailchannels_api_key'=>'synthetic-key','mailchannels_allowed_senders'=>['sender@example.com']];
$calls=0;$client=new \GuzzleHttp\Client(['handler'=>function($request,$options)use(&$calls){++$calls;return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(202,[],json_encode(['results'=>[['index'=>0,'status'=>'sent']]])));}]);
$t=new ApiTransport($settings,$client);$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$settings;
$mailer=new Mailer($t,$events);$mailer->send(message());check($calls===1,'native listener allows matching direct API transport');
foreach([['dsn'=>'null://null'],['transport_spool_type'=>'memory'],['mailchannels_api_key'=>'rotated-key'],['mailchannels_allowed_senders'=>['another@example.com']],['mailchannels_content_bytes'=>1234],['transport'=>'null']] as $change){
 $GLOBALS['TYPO3_CONF_VARS']['MAIL']=array_replace($settings,$change);$before=$GLOBALS['TYPO3_CONF_VARS']['MAIL'];$bad=false;
 try{$mailer->send(message());}catch(TransportFailure $e){$bad=$e->outcome==='configuration_invalid';}
 check($bad && $calls===1,'configuration conflict or stale transport rejects before HTTP');
 check($before===$GLOBALS['TYPO3_CONF_VARS']['MAIL'],'guard preserves administrator settings');
}
$log=new \Psr\Log\NullLogger();$manager=new class implements \TYPO3\CMS\Core\Log\LogManagerInterface {public function getLogger(string $name=''): \Psr\Log\LoggerInterface{return new \Psr\Log\NullLogger();}};
$factory=new TransportFactory(new \Symfony\Component\EventDispatcher\EventDispatcher(),$manager,$log,new \TYPO3\CMS\Core\Resource\Security\FileNameValidator());
$conflict=array_replace($settings,['dsn'=>'null://null']);$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$conflict;$actual=$factory->get($conflict);check($actual instanceof NullTransport,'native DSN bypass reproduced without constructing API transport');
$bad=false;try{(new Mailer($actual,$events))->send(message());}catch(TransportFailure){$bad=true;}check($bad,'external guard catches native DSN bypass');
$conflict=array_replace($settings,['transport_spool_type'=>'memory']);$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$conflict;$spool=$factory->get($conflict);$bad=false;
try{(new Mailer($spool,$events))->send(message());}catch(TransportFailure){$bad=true;}
check($bad && $spool->flushQueue(new NullTransport())===0,'guard blocks native memory spool before enqueue');
$cleanup=new class(new NullTransport()) extends Mailer {public function getRealTransport():TransportInterface{return $this->getTransport();}};
\TYPO3\CMS\Core\Utility\GeneralUtility::addInstance(MailerInterface::class,$cleanup);unset($spool);
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=['transport'=>'null'];$disabled=new Mailer(new NullTransport(),$events);$disabled->send(message());check($disabled->getSentMessage()!==null && $calls===1,'unrelated disabled routing preserved');
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$settings;
(new Mailer($t,$events))->send(message());check($calls===2,'restored matching configuration allows legitimate new send');
$attributes=(new ReflectionClass(ConfigurationGuard::class))->getAttributes(\TYPO3\CMS\Core\Attribute\AsEventListener::class);
check(count($attributes)===1 && $attributes[0]->newInstance()->identifier==='mailchannels/direct-transport-configuration','native event-listener registration metadata present');
echo "TYPO3_CONFIGURATION_COMPLETE $count checks; no provider calls\n";
