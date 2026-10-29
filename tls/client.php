<?php
require '/candidate/contract/vendor/autoload.php';
foreach(['EnvelopeMapper','BodyMapper','PayloadBuilder','TransportFailure','ApiTransport'] as $class)require '/candidate/Classes/Mail/'.$class.'.php';
use MailChannels\Typo3\Mail\{ApiTransport,TransportFailure};
use Symfony\Component\Mime\Email;
$t=new ApiTransport(['mailchannels_api_key'=>'tls-fixture-dummy-key','mailchannels_allowed_senders'=>['sender@example.com']]);
$m=(new Email())->from('sender@example.com')->to('recipient@example.com')->subject('Isolated fixture')->text('No provider traffic');
$start=microtime(true);$accepted=false;$outcome='unexpected';
try{$accepted=$t->send($m)!==null;$outcome='accepted';}catch(TransportFailure $e){$outcome=$e->outcome;}
echo json_encode(['accepted'=>$accepted,'outcome'=>$outcome,'seconds'=>round(microtime(true)-$start,3)],JSON_THROW_ON_ERROR);
