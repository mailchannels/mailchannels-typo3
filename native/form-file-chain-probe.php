<?php
// Included by form-probe.php inside its disposable, fully installed site.
// Values and completed-page state are seeded; this is not upload authorization.
$factory=$container->get(\TYPO3\CMS\Core\Resource\ResourceFactory::class);
$indexed=(new \TYPO3\CMS\Core\Resource\Index\Indexer($file->getStorage()))->createIndexEntry($file->getIdentifier());
$db=$container->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('sys_file_reference');
$db->insert('sys_file_reference',['uid_local'=>$indexed->getUid(),'uid_foreign'=>0,'tablenames'=>'tt_content','fieldname'=>'assets','pid'=>0]);
$reference=$factory->getFileReferenceObject((int)$db->lastInsertId(),[],true);
$extbaseReference=new \TYPO3\CMS\Extbase\Domain\Model\FileReference();
$extbaseReference->setOriginalResource($reference);
check($indexed->getUid()>0 && $reference->getContents()===$bytes,'persisted FAL reference resolves indexed fixture bytes');
$runtime['attachment']=$extbaseReference;
$subject->setOptions($stock);$context=new \TYPO3\CMS\Form\Domain\Finishers\FinisherContext($runtime,$request);
$before=count($payloads);$subject->execute($context);$p=$payloads[$before];
check(!$context->isCancelled() && count($payloads)===$before+1,'Extbase FileReference finisher sends once');
check(count($p['attachments'])===1 && $p['attachments'][0]['filename']==='fixture.txt' && base64_decode($p['attachments'][0]['content'],true)===$bytes,'Extbase reference preserves filename and binary bytes');

$secondBytes="Second upload\n\x00\x01\xfe";
file_put_contents($directory.'/second.txt',$secondBytes);
$secondFile=$factory->getFileObjectFromCombinedIdentifier('0:/fileadmin/second.txt');
$multiple=new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
$multiple->attach($extbaseReference);$multiple->attach($secondFile);
$runtime['attachment']=$multiple;
$subject->setOptions($stock);$context=new \TYPO3\CMS\Form\Domain\Finishers\FinisherContext($runtime,$request);
$before=count($payloads);$subject->execute($context);
check($context->isCancelled() && count($payloads)===$before,'stock multi-file template fails before API transport');
$failure=$logger->records[array_key_last($logger->records)][2]['exception'];
check($failure->getPrevious() instanceof \MailChannels\Typo3\Mail\TransportFailure && $failure->getPrevious()->outcome==='message_unsupported','stock multi-file rendering failure is not reported as acceptance');
$coreMail=$container->get(\TYPO3\CMS\Core\Mail\TemplatedEmailFactory::class)
    ->createWithOverrides($stock['templateRootPaths'],[],[],$request)
    ->setTemplate('Default')->format(\TYPO3\CMS\Core\Mail\FluidEmail::FORMAT_BOTH)
    ->assignMultiple(['form'=>$runtime,'finisherVariableProvider'=>$context->getFinisherVariableProvider()]);
$coreMail->getViewHelperVariableContainer()->addOrUpdate(\TYPO3\CMS\Form\ViewHelpers\RenderRenderableViewHelper::class,'formRuntime',$runtime);
$coreFailure=false;
try {$coreMail->getBody();} catch (\TYPO3Fluid\Fluid\Core\ViewHelper\InvalidArgumentValueException $e) {
    $coreFailure=str_contains($e->getMessage(),'"each"') && str_contains($e->getMessage(),'"string"');
}
check($coreFailure,'stock multi-file rendering fails in native Fluid without MailChannels transport');
// Separate transport coverage uses the existing custom template. This does not
// establish compatibility of the failing stock multi-file template.
$custom=array_replace($stock,['templateName'=>'FormFixture','templateRootPaths'=>['/app/native/templates/']]);
$subject->setOptions($custom);$context=new \TYPO3\CMS\Form\Domain\Finishers\FinisherContext($runtime,$request);
$subject->execute($context);$p=$payloads[$before];
check(!$context->isCancelled() && count($payloads)===$before+1,'mixed native ObjectStorage uploads send once');
check(array_column($p['attachments'],'filename')===['fixture.txt','second.txt'],'multiple upload filenames and order preserved');
check(array_map(fn($a)=>base64_decode($a['content'],true),$p['attachments'])===[$bytes,$secondBytes],'multiple upload binary contents preserved');
check(str_contains($p['content'][0]['value'],'Visitor ✓') && str_contains($p['content'][1]['value'],'Visitor ✓'),'custom multi-file mail retains plain and HTML form content');
$subject->setOptions(array_replace($custom,['attachUploads'=>false]));$context=new \TYPO3\CMS\Form\Domain\Finishers\FinisherContext($runtime,$request);
$before=count($payloads);$subject->execute($context);
check(!$context->isCancelled() && count($payloads)===$before+1 && !isset($payloads[$before]['attachments']),'disabled uploads omit all ObjectStorage attachments');
$runtime['attachment']=new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
$subject->setOptions($custom);$context=new \TYPO3\CMS\Form\Domain\Finishers\FinisherContext($runtime,$request);
$before=count($payloads);$subject->execute($context);
check(!$context->isCancelled() && count($payloads)===$before+1 && !isset($payloads[$before]['attachments']),'empty ObjectStorage sends without attachments');

// Run the real FormRuntime finisher loop through public render(). Only the
// completed-page state is seeded; no replacement loop or finisher subclass.
$runtime['attachment']=$extbaseReference;
(new \ReflectionProperty(\TYPO3\CMS\Form\Domain\Runtime\FormRuntime::class,'currentPage'))->setValue($runtime,null);
$beforeMarker=new class extends \TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher {
    public int $calls=0;
    protected function executeInternal() {++$this->calls;return 'BEFORE-MAIL';}
};
$afterMarker=new class extends \TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher {
    public int $calls=0;
    protected function executeInternal() {++$this->calls;return 'AFTER-MAIL';}
};
$beforeMarker->setFinisherIdentifier('FixtureBefore');$afterMarker->setFinisherIdentifier('FixtureAfter');
$subject->setOptions(array_replace($stock,['subject'=>'Receiver {visitor}']));
$sender=clone $subject;$sender->setFinisherIdentifier('EmailToSender');
$sender->setOptions(array_replace($stock,['subject'=>'Confirmation {visitor}','recipients'=>['visitor@example.com'=>'Visitor'],'carbonCopyRecipients'=>[],'blindCarbonCopyRecipients'=>[]]));
$definition->addFinisher($beforeMarker);$definition->addFinisher($subject);$definition->addFinisher($sender);$definition->addFinisher($afterMarker);
$status=202;$before=count($payloads);$output=$runtime->render();
check(count($payloads)===$before+2,'native chain sends receiver and confirmation once each');
check($beforeMarker->calls===1 && $afterMarker->calls===1 && $output==='BEFORE-MAILAFTER-MAIL','native successful chain executes following finisher in order');
check(array_column(array_slice($payloads,$before),'subject')===['Receiver Visitor ✓','Confirmation Visitor ✓'],'native chain resolves separate finisher subjects');
check($payloads[$before+1]['personalizations'][0]['to'][0]['email']==='visitor@example.com' && !isset($payloads[$before+1]['personalizations'][0]['cc']) && !isset($payloads[$before+1]['personalizations'][0]['bcc']),'confirmation uses its own recipients without receiver copies');
check(count($payloads[$before]['attachments'])===1 && count($payloads[$before+1]['attachments'])===1,'both stock native chain emails retain the referenced attachment');

$status=503;$before=count($payloads);$output=$runtime->render();
check(count($payloads)===$before+1,'first failed email stops chain before confirmation with no retry');
check($beforeMarker->calls===2 && $afterMarker->calls===1 && str_contains($output,'Fixture delivery could not be confirmed.') && !str_contains($output,'AFTER-MAIL'),'first email failure suppresses following success finisher');

$status=[202,503];$before=count($payloads);$output=$runtime->render();
check(count($payloads)===$before+2 && $status===[],'confirmation failure occurs after receiver acceptance without rollback or retry');
check($beforeMarker->calls===3 && $afterMarker->calls===1 && str_contains($output,'Fixture delivery could not be confirmed.') && !str_contains($output,'AFTER-MAIL'),'second email failure suppresses following success finisher');
$status=202;$before=count($payloads);$runtime->render();
check(count($payloads)===$before+2 && $afterMarker->calls===2,'new render repeats both sends; native chain supplies no durable duplicate protection');
