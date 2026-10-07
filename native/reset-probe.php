<?php
// Disposable installed-site fixture only. Never print token-bearing payloads.
$loader=require getcwd().'/vendor/autoload.php';
use TYPO3\CMS\Core\Core\{SystemEnvironmentBuilder,Bootstrap};
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Backend\Authentication\PasswordReset;
use TYPO3\CMS\Core\Http\{ServerRequest,NormalizedParams};
use MailChannels\Typo3\Mail\{ApiTransport,TransportFailure};
SystemEnvironmentBuilder::run(1,SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container=Bootstrap::init($loader);
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$original=$GLOBALS['TYPO3_CONF_VARS']['MAIL'];$originalBE=$GLOBALS['TYPO3_CONF_VARS']['BE'];
try {
 $settings=array_replace($original,['transport'=>ApiTransport::class,'dsn'=>'','transport_spool_type'=>'','defaultMailFromAddress'=>'sender@example.com','defaultMailFromName'=>'Fixture','mailchannels_api_key'=>'synthetic-only','mailchannels_allowed_senders'=>['sender@example.com']]);
 $GLOBALS['TYPO3_CONF_VARS']['MAIL']=$settings;
 $GLOBALS['TYPO3_CONF_VARS']['BE']['passwordReset']=true;
 $GLOBALS['TYPO3_CONF_VARS']['BE']['passwordResetForAdmins']=true;
 $payloads=[];$status=202;
 $client=new \GuzzleHttp\Client(['handler'=>function($request,$options)use(&$payloads,&$status){$payloads[]=json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response($status,[],json_encode(['results'=>[['index'=>0,'status'=>'sent']]])));}]);
 $mailer=new Mailer(new ApiTransport($settings,$client),$container->get(\Psr\EventDispatcher\EventDispatcherInterface::class));
 $logger=new class extends \Psr\Log\AbstractLogger {public array $levels=[];public function log($level,string|\Stringable $message,array $context=[]):void{$this->levels[]=$level;}};
 // Reconstruct the readonly native service with its installed dependencies. Only
 // the mailer and log recorder differ; no private state is mutated.
 $native=$container->get(PasswordReset::class);$reflection=new ReflectionClass($native);$args=[];
 foreach($reflection->getConstructor()->getParameters() as $parameter){$name=$parameter->getName();$args[] = match($name){'mailer'=>$mailer,'logger'=>$logger,default=>$reflection->getProperty($name)->getValue($native)};}
 $subject=$reflection->newInstanceArgs($args);
 $container->get(\TYPO3\CMS\Backend\Routing\UriBuilder::class)->setRequestContext(new \Symfony\Component\Routing\RequestContext('/typo3/','GET','fixture.invalid','https'));
 $request=(new ServerRequest('https://fixture.invalid/typo3/','GET','php://input',[],['REMOTE_ADDR'=>'127.0.0.1','SCRIPT_NAME'=>'/typo3/index.php','SCRIPT_FILENAME'=>getcwd().'/public/typo3/index.php','HTTP_HOST'=>'fixture.invalid','HTTPS'=>'on']))->withAttribute('applicationType',SystemEnvironmentBuilder::REQUESTTYPE_BE);
 $request=$request->withAttribute('normalizedParams',NormalizedParams::createFromRequest($request));
 $context=new \TYPO3\CMS\Core\Context\Context();
 $db=$container->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('be_users');
 $token=function()use($db){return $db->select(['password_reset_token'],'be_users',['username'=>'fixture-admin'])->fetchOne();};
 $logCount=function()use($db){return (int)$db->count('*','sys_log',[]);};
 check($subject->isEnabled(),'native reset eligibility enabled for synthetic administrator');
 $before=$logCount();$subject->initiateReset($request,$context,'admin@example.com');
 check(count($payloads)===1,'native reset sends once through candidate');
 check($payloads[0]['from']['email']==='sender@example.com' && $payloads[0]['personalizations'][0]['to'][0]['email']==='admin@example.com','native reset sender and recipient preserved');
 check(count($payloads[0]['content'])===2 && $payloads[0]['subject']!=='','native reset templates render subject and both bodies');
 $plain=$payloads[0]['content'][0]['value'];
 preg_match('~https://fixture\.invalid/[^\s<>"\']+~',$plain,$match);
 parse_str(parse_url($match[0]??'',PHP_URL_QUERY)??'',$query);
 check(isset($query['t'],$query['e'],$query['i']),'rendered native reset link carries token expiry and identity');
 $validRequest=$request->withQueryParams($query);
 check($subject->isValidResetTokenFromRequest($validRequest),'captured link validates against native stored token hash');
 $hash=$token();check(is_string($hash) && $hash!=='' && !str_contains($hash,$query['t']),'database stores hash rather than link token');
 check($logger->levels===['info'] && $logCount()===$before+1,'accepted reset records native success after transport');
 $tampered=$query;$tampered['t']='invalid';check(!$subject->isValidResetTokenFromRequest($request->withQueryParams($tampered)),'tampered token rejected');
 $status=503;$failed=false;
 try{$subject->initiateReset($request,$context,'admin@example.com');}catch(TransportFailure $e){$failed=$e->outcome==='acceptance_unconfirmed';}
 check($failed && count($payloads)===2,'unconfirmed reset send propagates without internal retry');
 check($logger->levels===['info'] && $logCount()===$before+1,'failed reset does not record native send success');
 check($token()!==$hash && !$subject->isValidResetTokenFromRequest($validRequest),'failed send replaces stored token and invalidates prior link');
} finally {$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$original;$GLOBALS['TYPO3_CONF_VARS']['BE']=$originalBE;}
check($GLOBALS['TYPO3_CONF_VARS']['MAIL']===$original && $GLOBALS['TYPO3_CONF_VARS']['BE']===$originalBE,'runtime settings restored');
echo "TYPO3_RESET_COMPLETE $count checks; no provider calls\n";
