<?php
require __DIR__.'/vendor/autoload.php';
foreach(['EnvelopeMapper','BodyMapper','PayloadBuilder'] as $class)require __DIR__.'/../Classes/Mail/'.$class.'.php';
use MailChannels\Typo3\Mail\PayloadBuilder;
use Symfony\Component\Mime\{Email,RawMessage};
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function fixture(){return (new Email())->from('sender@example.com')->to('to@example.com')->bcc('hidden@example.com')->subject('Subject ✓')->text('Plain')->html('<p>HTML</p>')->attach("\x00\xff",'file.bin');}
function build($m){return PayloadBuilder::build($m,null,['sender@example.com','agent@example.com']);}
function rejects($m,$label){$bad=false;try{build($m);}catch(InvalidArgumentException $e){$bad=$e->getPrevious()===null && !str_contains($e->getMessage(),'secret-fixture');}check($bad,$label);}
$m=fixture();$m->getHeaders()->addTextHeader('X-Workflow','welcome ✓');$m->sender('agent@example.com');$p=build($m);
check($p['subject']==='Subject ✓' && count($p['content'])===2,'complete payload contains Unicode subject and both bodies');
check(base64_decode($p['attachments'][0]['content'])==="\x00\xff",'complete payload retains binary attachment');
check($p['personalizations'][0]['bcc'][0]['email']==='hidden@example.com' && !isset($p['headers']['Bcc']),'Bcc remains personalization only');
check($p['headers']['X-Workflow']==='welcome ✓' && $p['headers']['Sender']==='agent@example.com','custom and mapped Sender headers coexist');
check(json_decode(json_encode($p,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR)===$p,'complete payload round-trips through JSON');
$m=fixture();$m->date(new DateTimeImmutable('2026-10-07T00:00:00Z'));check(str_contains(build($m)['headers']['Date'],'2026'),'structured Date preserved');
$m=fixture();$m->getHeaders()->addTextHeader('MIME-Version','1.0');check(!isset(build($m)['headers']['MIME-Version']),'valid MIME version handled by regenerated payload');
foreach(['Message-ID','DKIM-Signature','Authentication-Results','Received','Content-Type','Resent-To','ARC-Seal','X-Unsent'] as $name){$m=fixture();if($name==='Message-ID')$m->getHeaders()->addIdHeader($name,'secret-fixture@example.com');elseif($name==='Resent-To')$m->getHeaders()->addMailboxListHeader($name,['secret-fixture@example.com']);else $m->getHeaders()->addTextHeader($name,'secret-fixture');rejects($m,$name.' rejects without secret-bearing exception');}
$m=fixture();$m->getHeaders()->addTextHeader('X-Test','one');$m->getHeaders()->addTextHeader('x-test','two');rejects($m,'duplicate custom header rejects instead of losing value');
$m=fixture();$m->getHeaders()->addTextHeader('X-Test',"secret-fixture\r\nInjected: value");rejects($m,'custom-header injection rejects');
$m=fixture()->subject("secret-fixture\nInjected");rejects($m,'subject injection rejects');
$m=fixture()->subject("bad\xff");rejects($m,'invalid UTF-8 subject rejects');
$m=fixture();$m->getHeaders()->addTextHeader('MIME-Version','2.0');rejects($m,'unsupported MIME version rejects');
rejects(new RawMessage('secret-fixture'),'raw RFC message rejects explicitly');
$m=fixture();$m->getHeaders()->remove('Subject');check(build($m)['subject']==='','absent subject maps to explicit empty subject');
// Exercise the composition used by TYPO3, including its X-Mailer enrichment.
$t=new class implements \Symfony\Component\Mailer\Transport\TransportInterface {
 public array $payload=[];
 public function __toString():string{return 'inert-payload';}
 public function send(RawMessage $m,?\Symfony\Component\Mailer\Envelope $e=null):?\Symfony\Component\Mailer\SentMessage {$this->payload=PayloadBuilder::build($m,$e,['sender@example.com']);return new \Symfony\Component\Mailer\SentMessage($m,$e??\Symfony\Component\Mailer\Envelope::create($m));}
};
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=[];$mailer=new \TYPO3\CMS\Core\Mail\Mailer($t);$mailer->send(fixture());
check($t->payload['headers']['X-Mailer']==='TYPO3','actual core Mailer generates complete payload with X-Mailer');
echo "TYPO3_PAYLOAD_COMPLETE $count checks; no provider calls\n";
