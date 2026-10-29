<?php
$loader=require getcwd().'/vendor/autoload.php';
use TYPO3\CMS\Core\Core\{SystemEnvironmentBuilder,Bootstrap};
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
SystemEnvironmentBuilder::run(1,SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container=Bootstrap::init($loader);
$mode=getenv('TYPO3_LIFECYCLE_MODE');
if (!in_array($mode,['removed','dangling','restored'],true))throw new RuntimeException('Invalid fixture mode');
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
check(!$container->get(\TYPO3\CMS\Core\Package\PackageManager::class)->isPackageActive('mailchannels_email_api_candidate'),'removed package inactive after fresh boot');
check(!class_exists('MailChannels\\Typo3\\Mail\\ApiTransport'),'removed transport no longer autoloads');
$definitions=$container->get(ListenerProvider::class)->getAllListenerDefinitions();
check(!isset($definitions[BeforeMailerSentMessageEvent::class]['mailchannels/direct-transport-configuration']),'removed guard absent from rebuilt container');
if($mode==='dangling'){
 $before=$GLOBALS['TYPO3_CONF_VARS']['MAIL'];$failed=false;
 try{$container->get(MailerInterface::class);}catch(Throwable $e){$failed=str_contains($e->getMessage(),'ApiTransport');}
 check($failed,'dangling transport fails explicitly rather than choosing fallback');
 check($GLOBALS['TYPO3_CONF_VARS']['MAIL']===$before && $before['transport']==='MailChannels\\Typo3\\Mail\\ApiTransport','dangling operator configuration not rewritten');
}else{
 check($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']==='null','operator null route preserved');
 $mailer=$container->get(MailerInterface::class);
 $m=(new \Symfony\Component\Mime\Email())->from('sender@example.com')->to('recipient@example.com')->text('Synthetic');
 $mailer->send($m);
 check($mailer->getTransport() instanceof \Symfony\Component\Mailer\Transport\NullTransport && $mailer->getSentMessage()!==null,'native null route works after removal or recovery');
}
echo "TYPO3_LIFECYCLE_COMPLETE $mode $count checks; no provider calls\n";
