<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../Classes/Mail/BodyMapper.php';
use MailChannels\Typo3\Mail\BodyMapper;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\{DataPart,TextPart};
use Symfony\Component\Mime\Part\Multipart\{AlternativePart,MixedPart};
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function rejects($m,$label,$limit=20971520){$bad=false;try{BodyMapper::map($m,$limit);}catch(InvalidArgumentException){$bad=true;}check($bad,$label);}
$m=(new Email())->text("Plain ✓\nLine")->html('<p>HTML ✓</p>');$p=BodyMapper::map($m);
check($p['content']===[['type'=>'text/plain','value'=>"Plain ✓\r\nLine"],['type'=>'text/html','value'=>'<p>HTML ✓</p>']],'native alternative preserves decoded text and HTML');
foreach(['base64','quoted-printable','8bit'] as $encoding){$m=(new Email())->setBody(new TextPart('café','utf-8','plain',$encoding));check(BodyMapper::map($m)['content'][0]['value']==='café',$encoding.' decoded exactly once');}
$m=(new Email())->setBody(new TextPart("caf\xe9",'iso-8859-1'));check(BodyMapper::map($m)['content'][0]['value']==='café','declared charset converts to UTF-8');
$m=(new Email())->text('')->attach("\x00\xffbinary",'file.bin','application/octet-stream')->attach('','empty.txt','text/plain');$p=BodyMapper::map($m);
check($p['content'][0]['value']==='','explicit empty text retained');
check(base64_decode($p['attachments'][0]['content'],true)==="\x00\xffbinary",'binary attachment bytes preserved');
check($p['attachments'][1]['content']==='','empty attachment bytes preserved');
$image=new DataPart('image','logo.png','image/png');$image->setContentId('logo@example.com');
$m=(new Email())->html('<img src="cid:logo.png">')->addPart($image);$p=BodyMapper::map($m);
check(str_contains($p['content'][0]['value'],'cid:logo@example.com'),'Symfony CID reference rewrite preserved');
check($p['attachments'][0]['content_id']==='logo@example.com' && $p['attachments'][0]['disposition']==='inline','related inline metadata preserved');
$stream=fopen('php://temp','r+');fwrite($stream,'stream bytes');rewind($stream);$m=(new Email())->text('Body')->addPart(new DataPart($stream,'stream.bin'));
check(base64_decode(BodyMapper::map($m)['attachments'][0]['content'])==='stream bytes','native stream attachment conversion');fclose($stream);
rejects((new Email())->setBody(new AlternativePart(new TextPart('one'),new TextPart('two'))),'duplicate text alternatives reject');
rejects((new Email())->setBody(new MixedPart(new TextPart('one'),new TextPart('two'))),'mixed independent text parts reject instead of flattening');
rejects((new Email())->setBody(new TextPart("\xff",'utf-8')),'invalid UTF-8 rejects');
rejects((new Email())->setBody(new TextPart('body','not-a-charset')),'unknown charset rejects');
rejects((new Email())->text('12345'),'local content limit enforced',4);
rejects((new Email())->text('12')->attach('345','file.txt'),'aggregate content and attachment limit enforced',4);
rejects((new Email())->text('body')->attach('bytes','../file.txt'),'path-like attachment filename rejects');
rejects((new Email())->attach('bytes','file.txt'),'attachment-only message rejects');
$m=(new Email())->text('body')->addPart((new DataPart('bytes','file.txt'))->setContentId('file@example.com'));rejects($m,'attachment Content-ID without inline rejects');
$m=(new Email())->text('body')->addPart((new DataPart('one','one.png'))->asInline()->setContentId('duplicate@example.com'))->addPart((new DataPart('two','two.png'))->asInline()->setContentId('duplicate@example.com'));rejects($m,'duplicate inline ID rejects');
$part=new TextPart('body');$part->getHeaders()->addTextHeader('Content-Language','en');rejects((new Email())->setBody($part),'unmapped MIME part header rejects');
$m=(new Email())->setBody(new AlternativePart(new TextPart("\xe9",'iso-8859-1'),new TextPart("\xe9",'iso-8859-1','html')));rejects($m,'aggregate UTF-8 expansion respects limit',3);
$part=new AlternativePart(new TextPart('plain'),new TextPart('html','utf-8','html'));$part->getHeaders()->addTextHeader('Content-Language','en');rejects((new Email())->setBody($part),'unmapped multipart header rejects');
$part=(new DataPart('bytes','file.png'))->asInline();$part->getHeaders()->addIdHeader('Content-ID','custom@example.com');rejects((new Email())->text('body')->addPart($part),'manual header CID rejects instead of silently replacing it');
$file=tempnam(sys_get_temp_dir(),'typo3-mime-');
try {file_put_contents($file,"file\x00bytes");$m=(new Email())->text('body')->addPart(DataPart::fromPath($file,'file.bin','application/octet-stream'));check(base64_decode(BodyMapper::map($m)['attachments'][0]['content'])==="file\x00bytes",'native deferred file attachment preserves bytes');}finally{unlink($file);}
echo "TYPO3_BODY_COMPLETE $count checks; no provider calls\n";
