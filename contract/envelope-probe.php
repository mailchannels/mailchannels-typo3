<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../Classes/Mail/EnvelopeMapper.php';
use MailChannels\Typo3\Mail\EnvelopeMapper;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\{Address,Email};
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function rejects($message,$envelope,$allowed,$label){$bad=false;try{EnvelopeMapper::map($message,$envelope,$allowed);}catch(InvalidArgumentException $e){$bad=true;check(!str_contains($e->getMessage(),'secret@example.com'),'error omits supplied address');}check($bad,$label);}
$allowed=['sender@example.com','bounce@example.com','agent@example.com'];
$m=(new Email())->from(new Address('sender@example.com','Sender ✓'))->to(new Address('to@example.com','Visible'))->cc('cc@example.com')->bcc('hidden@example.com')->replyTo('reply@example.net');
$p=EnvelopeMapper::map($m,null,$allowed);
check($p['from']['name']==='Sender ✓','UTF-8 display name preserved');
check($p['personalizations'][0]['to'][0]['email']==='to@example.com','visible To preserved');
check($p['personalizations'][0]['cc'][0]['email']==='cc@example.com','Cc preserved separately');
check($p['personalizations'][0]['bcc'][0]['email']==='hidden@example.com','Bcc remains separate from visible recipients');
check($p['reply_to']['email']==='reply@example.net','Reply-To does not require sender authorization');
check($p['envelope_from']['email']==='sender@example.com','derived envelope sender mapped');
$explicit=new Envelope(new Address('bounce@example.com'),[new Address('hidden@example.com'),new Address('to@example.com'),new Address('cc@example.com')]);
$p=EnvelopeMapper::map($m,$explicit,$allowed);
check($p['envelope_from']['email']==='bounce@example.com','explicit envelope sender is authoritative');
check($p['personalizations'][0]['to'][0]['name']==='Visible','envelope reorder retains visible display name');
$m->sender(new Address('agent@example.com','Sending agent'))->returnPath('bounce@example.com');
$p=EnvelopeMapper::map($m,null,$allowed);
check($p['envelope_from']['email']==='agent@example.com','Symfony Sender precedence retained over Return-Path');
check($p['headers']['Sender']==='"Sending agent" <agent@example.com>','Sender header remains distinct from From');
$p=EnvelopeMapper::map($m,$explicit,$allowed);
check($p['envelope_from']['email']==='bounce@example.com' && isset($p['headers']['Sender']),'explicit envelope overrides derived routing while preserving Sender');
rejects($m,new Envelope(new Address('bounce@example.com'),[new Address('secret@example.com')]),$allowed,'different envelope recipient rejects');
rejects($m,null,['sender@example.com'],'unauthorized Sender rejects');
rejects($m,$explicit,['sender@example.com','agent@example.com'],'unauthorized explicit envelope sender rejects');
$bad=clone $m;$bad->from('secret@example.com');rejects($bad,$explicit,$allowed,'unauthorized visible From rejects');
$bad=clone $m;$bad->replyTo('first@example.com','second@example.com');rejects($bad,null,$allowed,'multiple Reply-To rejects');
$bad=(new Email())->from('sender@example.com')->bcc('hidden@example.com');rejects($bad,null,$allowed,'Bcc-only rejects without inventing visible To');
$bad=clone $m;$bad->addFrom('other@example.com');rejects($bad,null,$allowed,'multiple From rejects');
rejects($m,null,[null],'invalid sender policy rejects');
$before=serialize($m);EnvelopeMapper::map($m,$explicit,$allowed);check(serialize($m)===$before,'input message remains unchanged');
$nativeTransport=new class($allowed) implements \Symfony\Component\Mailer\Transport\TransportInterface {
 public array $payload=[];
 public function __construct(private array $allowed){}
 public function __toString():string{return 'inert-envelope-mapper';}
 public function send(\Symfony\Component\Mime\RawMessage $message,?Envelope $envelope=null):?\Symfony\Component\Mailer\SentMessage {
  if(!$message instanceof Email)throw new InvalidArgumentException('Typed Email required.');
  $this->payload=EnvelopeMapper::map($message,$envelope,$this->allowed);
  return new \Symfony\Component\Mailer\SentMessage($message,$envelope??Envelope::create($message));
 }
};
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=[];
$native=(new Email())->from('sender@example.com')->to('to@example.com')->text('Synthetic');
$mailer=new \TYPO3\CMS\Core\Mail\Mailer($nativeTransport);
$mailer->send($native);
check($nativeTransport->payload['personalizations'][0]['to'][0]['email']==='to@example.com','actual TYPO3 Mailer passes supported routing to mapper');
$events=new class implements \Psr\EventDispatcher\EventDispatcherInterface {
 public function dispatch(object $event):object {
  if($event instanceof \TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent)$event->setEnvelope(new Envelope(new Address('bounce@example.com'),[new Address('different@example.com')]));
  return $event;
 }
};
$nativeMailer=new \TYPO3\CMS\Core\Mail\Mailer($nativeTransport,$events);
$rejected=false;try{$nativeMailer->send($native);}catch(InvalidArgumentException){$rejected=true;}
check($rejected,'native before-event recipient rewrite rejects at conversion boundary');
echo "TYPO3_ENVELOPE_COMPLETE $count checks; no provider calls\n";
