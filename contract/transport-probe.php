<?php
require __DIR__.'/vendor/autoload.php';
foreach(['EnvelopeMapper','BodyMapper','PayloadBuilder','TransportFailure','ApiTransport'] as $class)require __DIR__.'/../Classes/Mail/'.$class.'.php';
use MailChannels\Typo3\Mail\{ApiTransport,TransportFailure};
use GuzzleHttp\{Client,HandlerStack,Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Mime\Email;
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function message(){return (new Email())->from('sender@example.com')->to('to@example.com')->subject('Synthetic')->text('No live email');}
$settings=['mailchannels_api_key'=>'synthetic-secret','mailchannels_allowed_senders'=>['sender@example.com']];
$accepted=json_encode(['results'=>[['index'=>0,'status'=>'sent']]]);
$history=[];$stack=HandlerStack::create(new MockHandler([new Response(202,[],$accepted),new Response(202,[],$accepted)]));$stack->push(Middleware::history($history));
$t=new ApiTransport($settings,new Client(['handler'=>$stack]));
$GLOBALS['TYPO3_CONF_VARS']['MAIL']=[];$mailer=new \TYPO3\CMS\Core\Mail\Mailer($t);
$mailer->send(message());check($mailer->getSentMessage() instanceof \Symfony\Component\Mailer\SentMessage,'native Mailer returns accepted SentMessage');
check(count($history)===1,'one request for accepted send');
$request=$history[0]['request'];$options=$history[0]['options'];
check((string)$request->getUri()==='https://api.mailchannels.net/tx/v1/send' && $request->getMethod()==='POST','fixed HTTPS send endpoint');
check($request->getHeaderLine('X-Api-Key')==='synthetic-secret','credential sent only in API header');
check($options['verify']===true && $options['allow_redirects']===false && $options['proxy']==='','certificate validation redirect and proxy policy');
check($options['timeout']===15 && $options['connect_timeout']===5 && $options['http_errors']===false,'bounded timeout and explicit HTTP status handling');
check(json_decode((string)$request->getBody(),true)['headers']['X-Mailer']==='TYPO3','native full payload submitted');
$mailer->send(message());check(count($history)===2,'two legitimate same-type messages send independently');
foreach([
 [200,$accepted,'acceptance_unconfirmed'],[302,'','acceptance_unconfirmed'],[400,'secret-body','rejected'],[429,'secret-body','rejected'],[408,'secret-body','acceptance_unconfirmed'],[500,'secret-body','acceptance_unconfirmed'],
 [202,'not-json-secret-body','acceptance_unconfirmed'],[202,json_encode(['results'=>[]]),'acceptance_unconfirmed'],[202,json_encode(['results'=>[['index'=>1,'status'=>'sent']]]),'acceptance_unconfirmed'],[202,json_encode(['results'=>[['index'=>0,'status'=>'failed']]]),'rejected'],[202,json_encode(['results'=>[['index'=>0,'status'=>'queued']]]),'acceptance_unconfirmed'],[202,str_repeat('x',65537),'acceptance_unconfirmed']
] as [$status,$body,$outcome]) {
 $calls=[];$stack=HandlerStack::create(new MockHandler([new Response($status,[],$body)]));$stack->push(Middleware::history($calls));$t=new ApiTransport($settings,new Client(['handler'=>$stack]));$caught=null;
 try{$t->send(message());}catch(TransportFailure $e){$caught=$e;}
 check($caught?->outcome===$outcome && count($calls)===1 && $caught->getPrevious()===null && !str_contains($caught->getMessage(),'secret'),'status/response failure classified without retry or sensitive exception');
}
$calls=0;$client=new Client(['handler'=>function($req,$options)use(&$calls){++$calls;throw new RuntimeException('synthetic-secret body');}]);$t=new ApiTransport($settings,$client);$caught=null;try{$t->send(message());}catch(TransportFailure $e){$caught=$e;}
check($caught?->outcome==='acceptance_unconfirmed' && $calls===1 && $caught->getPrevious()===null,'transport exception sanitized and never retried');
$bad=message()->from('wrong@example.com');try{$t->send($bad);}catch(TransportFailure $e){check($e->outcome==='message_unsupported' && $calls===1,'invalid message fails before HTTP');}
foreach([['dsn'=>'null://null'],['transport_spool_type'=>'memory'],['mailchannels_api_key'=>"bad\nkey"],['mailchannels_allowed_senders'=>[]]] as $override){$bad=false;try{new ApiTransport(array_replace($settings,$override),$client);}catch(TransportFailure $e){$bad=$e->outcome==='configuration_invalid';}check($bad,'conflicting or invalid configuration rejects');}
$t=new ApiTransport($settings,$client);ob_start();var_dump($t);$debug=ob_get_clean();check(!str_contains($debug,'synthetic-secret') && !str_contains((string)$t,'synthetic-secret'),'debug and string representation redact credentials');
$bad=false;try{serialize($t);}catch(LogicException){$bad=true;}check($bad,'credential-bearing transport cannot serialize');
$calls=0;$client=new Client(['handler'=>function($req,$options)use(&$calls){++$calls;$options['sink']->write(str_repeat('x',65537));throw new RuntimeException('Should not reach');}]);$t=new ApiTransport($settings,$client);$caught=null;try{$t->send(message());}catch(TransportFailure $e){$caught=$e;}
check($caught?->outcome==='acceptance_unconfirmed' && $calls===1,'response sink rejects oversized write');
echo "TYPO3_TRANSPORT_COMPLETE $count checks; no provider calls\n";
