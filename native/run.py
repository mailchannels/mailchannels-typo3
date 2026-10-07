"""Fresh isolated TYPO3/SQLite site, synthetic credentials and disabled mail only."""
from pathlib import Path
import subprocess, shutil, uuid
root=Path(__file__).resolve().parents[1]
name='mctypo3-native-'+uuid.uuid4().hex[:10]
site=root/'.native-work'/name
site.mkdir(parents=True)
logs=[]
image='mailchannels-typo3-contract:php84'
workers=[]
def run(args,timeout=120):
    if args[:2]==['docker','run']:
        worker=name+'-'+str(len(workers));workers.append(worker)
        args=args[:2]+['--name',worker]+args[2:]
    result=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
    logs.append(result.stdout+result.stderr)
    if result.returncode:raise RuntimeError(result.stdout+result.stderr)
    return result.stdout+result.stderr
base=['docker','run','--rm','--network','none','-v',str(root)+':/app','-w','/app/.native-work/'+name]
try:
    for filename in ['composer.json','composer.lock']:shutil.copy2(root/'native'/filename,site/filename)
    print('Installing locked disposable TYPO3 dependencies',flush=True)
    run(['docker','run','--rm','-e','COMPOSER_ALLOW_SUPERUSER=1','-v',str(root)+':/app','-w','/app/.native-work/'+name,image,'composer','install','--no-interaction','--prefer-dist','--no-progress'],timeout=300)
    setup="from pathlib import Path; p=Path('/site/config/system'); p.mkdir(parents=True,exist_ok=True); (p/'additional.php').write_text(\"<?php\\n$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']='null';\\n$GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn']='';\\n$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_spool_type']='';\\n\")"
    run(['docker','run','--rm','--network','none','-v',str(site)+':/site','python:3.12-slim','python','-c',setup])
    text=run(base+['-e','TYPO3_SETUP_ADMIN_PASSWORD=Isolated-Fixture-Only-12345',image,'php','-d','disable_functions=mail','vendor/bin/typo3','setup','--no-interaction','--driver=sqlite','--admin-username=fixture-admin','--admin-email=admin@example.com','--project-name=Isolated TYPO3 fixture','--server-type=other','--create-site=http://fixture.invalid/'])
    assert 'TYPO3 Setup is done' in text,text
    print('Installed isolated TYPO3 site with null mail routing',flush=True)
    text=run(base+[image,'php','-d','disable_functions=mail','/app/native/probe.php'])
    assert 'TYPO3_NATIVE_COMPLETE 14 checks; no provider calls' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==14,text
    print(text,end='',flush=True)
    text=run(base+[image,'php','-d','disable_functions=mail','/app/native/reset-probe.php'])
    assert 'TYPO3_RESET_COMPLETE 13 checks; no provider calls' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==13,text
    print(text,end='',flush=True)
    text=run(base+[image,'php','-d','disable_functions=mail','/app/native/form-probe.php'])
    assert 'TYPO3_FORM_COMPLETE 21 checks; no provider calls' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==21,text
    print(text,end='',flush=True)
    # Remove only from this fresh project; Composer unlinks the path package.
    run(base+['-e','COMPOSER_ALLOW_SUPERUSER=1','-e','COMPOSER_DISABLE_NETWORK=1',image,'composer','remove','mailchannels/typo3-email-api-candidate','--no-interaction','--no-progress'],timeout=180)
    for mode in ['removed','dangling','restored']:
        if mode!='removed':
            transport='MailChannels\\Typo3\\Mail\\ApiTransport' if mode=='dangling' else 'null'
            config="<?php\n$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']='"+transport+"';\n$GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn']='';\n$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_spool_type']='';\n"
            write="from pathlib import Path; Path('/site/config/system/additional.php').write_text("+repr(config)+")"
            run(['docker','run','--rm','--network','none','-v',str(site)+':/site','python:3.12-slim','python','-c',write])
        text=run(base+['-e','TYPO3_LIFECYCLE_MODE='+mode,image,'php','-d','disable_functions=mail','/app/native/lifecycle-probe.php'])
        assert f'TYPO3_LIFECYCLE_COMPLETE {mode} 5 checks; no provider calls' in text,text
        assert sum(line.startswith('PASS ') for line in text.splitlines())==5,text
        print(text,end='',flush=True)
    assert (root/'Classes/Mail/ApiTransport.php').exists(),'Composer removal changed source candidate'
    print('TYPO3_NATIVE_AND_LIFECYCLE_COMPLETE 63 checks',flush=True)
finally:
    for worker in workers:subprocess.run(['docker','rm','-f',worker],capture_output=True)
    cleanup="from pathlib import Path; import shutil; [(shutil.rmtree(p) if p.is_dir() and not p.is_symlink() else p.unlink()) for p in Path('/site').iterdir()]"
    result=subprocess.run(['docker','run','--rm','--network','none','-v',str(site)+':/site','python:3.12-slim','python','-c',cleanup],capture_output=True,text=True)
    if result.returncode:raise RuntimeError('Site cleanup failed')
    site.rmdir()
    logs.append('TYPO3_NATIVE_CLEANUP_COMPLETE')
    (root/'.native-work'/(name+'-results.txt')).write_text('\n'.join(logs))
    print('TYPO3_NATIVE_CLEANUP_COMPLETE',flush=True)
