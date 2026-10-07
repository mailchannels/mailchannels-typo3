<?php
$loader=require getcwd().'/vendor/autoload.php';
use TYPO3\CMS\Core\Core\{SystemEnvironmentBuilder,Bootstrap};
use TYPO3\CMS\Core\Mail\{Mailer,TemplatedEmailFactory};
use TYPO3\CMS\Form\Domain\Finishers\{EmailFinisher,FinisherContext};
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use MailChannels\Typo3\Mail\{ApiTransport,TransportFailure};
SystemEnvironmentBuilder::run(1,SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container=Bootstrap::init($loader);$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$original=$GLOBALS['TYPO3_CONF_VARS']['MAIL'];
try {
 $settings=array_replace($original,['transport'=>ApiTransport::class,'dsn'=>'','transport_spool_type'=>'','mailchannels_api_key'=>'synthetic-only','mailchannels_allowed_senders'=>['sender@example.com']]);
 $GLOBALS['TYPO3_CONF_VARS']['MAIL']=$settings;
 $payloads=[];$status=202;
 $client=new \GuzzleHttp\Client(['handler'=>function($request,$options)use(&$payloads,&$status){$payloads[]=json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(is_array($status)?array_shift($status):$status,[],json_encode(['results'=>[['index'=>0,'status'=>'sent']]])));}]);
 $events=$container->get(\Psr\EventDispatcher\EventDispatcherInterface::class);
 $mailer=new Mailer(new ApiTransport($settings,$client),$events);
 $logger=new class extends \Psr\Log\AbstractLogger {public array $records=[];public function log($level,string|\Stringable $message,array $context=[]):void{$this->records[]=[$level,$message,$context];}};
 $subject=new EmailFinisher($events,$container->get(TemplatedEmailFactory::class),$mailer);
 $subject->injectTranslationService($container->get(\TYPO3\CMS\Form\Service\TranslationService::class));
 $subject->injectFormValueResolver(new \TYPO3\CMS\Form\Service\FormValueResolver($container->get(\TYPO3\CMS\Form\Service\TranslationService::class)));
 $subject->injectViewFactory($container->get(\TYPO3\CMS\Core\View\ViewFactoryInterface::class));
 $subject->setLogger($logger);$subject->setFinisherIdentifier('EmailToReceiver');
 $definition=new FormDefinition('fixture',['formElementsDefinition'=>['Form'=>[],'Page'=>['implementationClassName'=>\TYPO3\CMS\Form\Domain\Model\FormElements\Page::class],'FileUpload'=>['implementationClassName'=>\TYPO3\CMS\Form\Domain\Model\FormElements\FileUpload::class],'Text'=>['implementationClassName'=>\TYPO3\CMS\Form\Domain\Model\FormElements\GenericFormElement::class]]]);
 $definition->setRenderingOption('templateRootPaths',['EXT:form/Resources/Private/Frontend/Templates/']);
 $definition->setRenderingOption('translation',['translationFiles'=>['EXT:form/Resources/Private/Language/locallang.xlf']]);
 $page=$definition->createPage('page');$page->createElement('visitor','Text');$page->createElement('email','Text');
 $http=(new \TYPO3\CMS\Core\Http\ServerRequest('https://fixture.invalid/contact','GET','php://input',[],['REMOTE_ADDR'=>'127.0.0.1','SCRIPT_NAME'=>'/index.php','SCRIPT_FILENAME'=>getcwd().'/public/index.php','HTTP_HOST'=>'fixture.invalid','HTTPS'=>'on']))
  ->withAttribute('applicationType',SystemEnvironmentBuilder::REQUESTTYPE_FE)
  ->withAttribute('extbase',new \TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters());
 $http=$http->withAttribute('normalizedParams',\TYPO3\CMS\Core\Http\NormalizedParams::createFromRequest($http));
 $request=new \TYPO3\CMS\Extbase\Mvc\Request($http);
 $runtime=$container->get(FormRuntime::class);$runtime->setFormDefinition($definition);$runtime->setRequest($request);$runtime->initialize();
 // Seed already-validated values. Browser submission/validation is outside this probe.
 $runtime['visitor']='Visitor ✓';$runtime['email']='visitor@example.com';
 $options=['senderAddress'=>'sender@example.com','senderName'=>'Fixture','recipients'=>['recipient@example.com'=>'Recipient'],'replyToRecipients'=>['{email}'=>'{visitor}'],'carbonCopyRecipients'=>['copy@example.com'=>'Copy'],'blindCarbonCopyRecipients'=>['hidden@example.com'=>'Hidden'],'subject'=>'Contact {visitor}','templateName'=>'FormFixture','templateRootPaths'=>['/app/native/templates/'],'attachUploads'=>false,'errorMessage'=>'Fixture delivery could not be confirmed.'];
 $subject->setOptions($options);$context=new FinisherContext($runtime,$request);
 check($subject->execute($context)===null && !$context->isCancelled(),'successful native finisher completes without cancellation');
 check(count($payloads)===1,'native finisher sends one API request');
 $p=$payloads[0];
 check($p['subject']==='Contact Visitor ✓','native finisher resolves submitted subject field');
 check($p['reply_to']['email']==='visitor@example.com' && $p['reply_to']['name']==='Visitor ✓','native finisher resolves reply-to fields');
 check($p['personalizations'][0]['to'][0]['email']==='recipient@example.com' && $p['personalizations'][0]['cc'][0]['email']==='copy@example.com' && $p['personalizations'][0]['bcc'][0]['email']==='hidden@example.com','native finisher preserves To Cc and Bcc roles');
 check(count($p['content'])===2 && str_contains($p['content'][0]['value'],'Visitor ✓') && str_contains($p['content'][1]['value'],'Visitor ✓'),'native finisher templates render form values in both bodies');
 check(!str_contains(json_encode($p['content']),'hidden@example.com'),'blind recipient absent from rendered bodies');
 check($logger->records===[],'successful finisher has no error log');
 $status=503;$context=new FinisherContext($runtime,$request);$subject->setOptions($options);$result=$subject->execute($context);
 check($context->isCancelled() && count($payloads)===2,'unconfirmed send cancels finishers without retry');
 check(is_string($result) && str_contains($result,'Fixture delivery could not be confirmed.'),'native error template renders configured failure message');
 $error=$logger->records[0][2]['exception']??null;
 check(count($logger->records)===1 && $logger->records[0][0]==='error' && $error?->getPrevious() instanceof TransportFailure && $error->getPrevious()->outcome==='acceptance_unconfirmed','native logged exception preserves transport outcome');
 check(!str_contains($error->getMessage(),'synthetic-only') && !str_contains($result,'synthetic-only'),'error message and rendered failure omit synthetic key');
 // Exercise stock mail templates and the real FAL File branch, not a file stub.
 $status=202;
 $page->createElement('attachment','FileUpload')->setLabel('Attachment');
 $definition->getElementByIdentifier('visitor')->setLabel('Visitor');
 $definition->getElementByIdentifier('email')->setLabel('Email');
 $bytes="Synthetic attachment\nBinary: \x00\xff\n";
 $directory=getcwd().'/public/fileadmin';if(!is_dir($directory))mkdir($directory,0770,true);
 file_put_contents($directory.'/fixture.txt',$bytes);
 $file=$container->get(\TYPO3\CMS\Core\Resource\ResourceFactory::class)->getFileObjectFromCombinedIdentifier('0:/fileadmin/fixture.txt');
 check($file instanceof \TYPO3\CMS\Core\Resource\File && $file->getContents()===$bytes,'native FAL resolves isolated fixture bytes');
 $runtime['attachment']=$file;
 $stock=array_replace($options,['templateName'=>'Default','templateRootPaths'=>[10=>'EXT:form/Resources/Private/Frontend/Templates/Finishers/Email/'],'attachUploads'=>true]);
 $subject->setOptions($stock);$context=new FinisherContext($runtime,$request);
 $stockResult=$subject->execute($context);
 check($stockResult===null && !$context->isCancelled() && count($payloads)===3,'stock templates and native FAL attachment send once');
 $p=$payloads[2];
 check(count($p['content'])===2 && str_contains($p['content'][0]['value'],'Visitor ✓') && str_contains($p['content'][1]['value'],'Visitor ✓'),'stock plain and HTML templates render field values');
 check(str_contains($p['content'][0]['value'],'fixture.txt') && str_contains($p['content'][1]['value'],'fixture.txt'),'stock templates render native upload filename');
 check(count($p['attachments']??[])===1 && base64_decode($p['attachments'][0]['content'],true)===$bytes,'native uploaded attachment bytes preserved exactly');
 check($p['attachments'][0]['filename']==='fixture.txt' && $p['attachments'][0]['disposition']==='attachment','native upload filename and disposition preserved');
 $subject->setOptions(array_replace($stock,['attachUploads'=>false,'addHtmlPart'=>'0']));$context=new FinisherContext($runtime,$request);$subject->execute($context);
 check(!$context->isCancelled() && count($payloads)===4 && !isset($payloads[3]['attachments']),'disabled upload attachment option omits file');
 check(count($payloads[3]['content'])===1 && $payloads[3]['content'][0]['type']==='text/plain','native string-zero HTML option produces plain-only message');
 require __DIR__.'/form-file-chain-probe.php';
} finally {$GLOBALS['TYPO3_CONF_VARS']['MAIL']=$original;}
check($GLOBALS['TYPO3_CONF_VARS']['MAIL']===$original,'runtime mail settings restored');
echo "TYPO3_FORM_COMPLETE $count checks; no provider calls\n";
