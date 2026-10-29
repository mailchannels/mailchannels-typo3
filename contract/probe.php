<?php
require __DIR__.'/vendor/autoload.php';
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\{TransportFactory,Mailer,MailerInterface,MemorySpool};
use TYPO3\CMS\Core\Mail\Event\{BeforeMailerSentMessageEvent,AfterMailerSentMessageEvent};
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\Log\LogManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
class CaptureTransport implements TransportInterface {
 public array $calls=[]; public bool $fail=false;
 public function __construct(public array $settings=[]) {}
 public function __toString():string{return 'fixture';}
 public function send(RawMessage $message,?Envelope $envelope=null):?SentMessage {
  $this->calls[]=[$message,$envelope];
  if($this->fail)throw new TransportException('Synthetic uncertain acceptance');
  return new SentMessage($message,$envelope??Envelope::create($message));
 }
}
$count=0;
function check(bool $ok,string $label):void {global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$log=new NullLogger();
$manager=new class implements LogManagerInterface {public function getLogger(string $name=''): \Psr\Log\LoggerInterface{return new NullLogger();}};
$factory=new TransportFactory(new EventDispatcher(),$manager,$log,new FileNameValidator());
$settings=['transport'=>CaptureTransport::class,'dsn'=>'','transport_spool_type'=>'','fixture'=>'synthetic'];
$transport=$factory->get($settings);
check($transport instanceof CaptureTransport,'native factory instantiates custom transport');
check($transport->settings===$settings,'custom constructor receives complete MAIL settings');
check($factory->get(array_replace($settings,['dsn'=>'null://null'])) instanceof NullTransport,'nonempty DSN overrides custom transport');
check($factory->get(array_replace($settings,['transport'=>'null','dsn'=>'invalid://fixture'])) instanceof NullTransport,'explicit null preserves disabled transport before DSN');
foreach ([[],['transport'=>'spool'],['transport'=>'dsn','dsn'=>''],['transport'=>stdClass::class]] as $bad) {
 $rejected=false;try{$factory->get($bad);}catch(Throwable $e){$rejected=true;}
 check($rejected,'invalid native transport configuration rejects');
}
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$settings;
$events=new class implements \Psr\EventDispatcher\EventDispatcherInterface {
 public int $before=0;public int $after=0;
 public function dispatch(object $event):object {
  if($event instanceof BeforeMailerSentMessageEvent){++$this->before;$event->setEnvelope(new Envelope(new Address('bounce@example.com'),[new Address('actual@example.com')]));}
  if($event instanceof AfterMailerSentMessageEvent)++$this->after;
  return $event;
 }
};
$mailer=new Mailer($transport,$events);
$email=(new Email())->from('sender@example.com')->to('visible@example.com')->subject('Synthetic fixture')->text('Fixture');
$mailer->send($email);
check($events->before===1 && $events->after===1,'native before and after events fire on acceptance');
check($transport->calls[0][1]->getRecipients()[0]->getAddress()==='actual@example.com','event-modified envelope reaches transport independently of visible To');
check($transport->calls[0][0]->getHeaders()->get('X-Mailer')->getBodyAsString()==='TYPO3','native X-Mailer enrichment preserved');
check($mailer->getSentMessage() instanceof SentMessage,'accepted result exposed as SentMessage');
$previous=$mailer->getSentMessage();$transport->fail=true;$rejected=false;
try{$mailer->send($email);}catch(TransportException $e){$rejected=true;}
check($rejected && $events->before===2 && $events->after===1,'transport exception propagates with no after event');
check($mailer->getSentMessage()===$previous,'failed reuse retains prior SentMessage; caller must respect exception');
$spool=$factory->get(array_replace($settings,['dsn'=>'null://null','transport_spool_type'=>'memory']));
check($spool instanceof MemorySpool,'memory spool precedes custom transport and DSN');
$spool->send($email);
$failing=new CaptureTransport();$failing->fail=true;$rejected=false;
try{$spool->flushQueue($failing);}catch(TransportException $e){$rejected=true;}
check($rejected && count($failing->calls)===3,'native memory spool retries transport exception three times');
check($spool->flushQueue(new NullTransport())===0,'failed memory-spool message no longer queued after exhausted retries');
// Native destructor asks for a real mailer even with an empty queue. Supply inert one.
$cleanupMailer=new class(new NullTransport()) extends Mailer {public function getRealTransport():TransportInterface{return $this->getTransport();}};
GeneralUtility::addInstance(MailerInterface::class,$cleanupMailer);
unset($spool);
check(count($failing->calls)===3,'spool destruction does not call failed transport again');
echo "TYPO3_TRANSPORT_CONTRACT_COMPLETE $count checks; no provider calls\n";
