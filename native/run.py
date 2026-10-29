"""Fresh isolated TYPO3/SQLite site, synthetic credentials and disabled mail only."""
from pathlib import Path
import subprocess, shutil, uuid, argparse, json
parser=argparse.ArgumentParser()
parser.add_argument("--test-core-display-fix",action="store_true",help="Test proposed core patch in disposable site only")
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
name='mctypo3-native-'+uuid.uuid4().hex[:10]
site=root/'.native-work'/name
preexisting_sites=sorted(p.name for p in site.parent.glob('*') if p.is_dir())
site.mkdir(parents=True)
logs=[]
commands=[]
http=None
preexisting_containers=[]
http_results=root/'.native-work'/(name+'-http')
image='mailchannels-typo3-contract:php84'
workers=[]
def run(args,timeout=120):
    if args[:2]==['docker','run'] and '--name' not in args:
        worker=name+'-'+str(len(workers));workers.append(worker)
        args=args[:2]+['--name',worker]+args[2:]
    try:
        result=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
    except subprocess.TimeoutExpired as error:
        stdout=(error.stdout or b'').decode(errors='replace')
        stderr=(error.stderr or b'').decode(errors='replace')
        commands.append({'command':args,'status':'timeout','stdout':stdout,'stderr':stderr})
        logs.append(stdout+stderr)
        raise
    commands.append({"command":args,"status":result.returncode,
                     "stdout":result.stdout,"stderr":result.stderr})
    logs.append(result.stdout+result.stderr)
    if result.returncode:raise RuntimeError(result.stdout+result.stderr)
    return result.stdout+result.stderr
base=['docker','run','--rm','--network','none','-v',str(root)+':/app','-w','/app/.native-work/'+name]
try:
    preexisting_containers=run(['docker','ps','-aq','--no-trunc']).splitlines()
    image=run(['docker','image','inspect',image,'--format','{{.Id}}']).strip()
    run(base+[image,'composer','--version','--no-ansi'])
    for filename in ['composer.json','composer.lock']:shutil.copy2(root/'native'/filename,site/filename)
    print('Installing locked disposable TYPO3 dependencies',flush=True)
    run(['docker','run','--rm','-e','COMPOSER_ALLOW_SUPERUSER=1','-v',str(root)+':/app','-w','/app/.native-work/'+name,image,'composer','install','--no-interaction','--prefer-dist','--no-progress'],timeout=300)
    if args.test_core_display_fix:
        text=run(['docker','run','--rm','--network','none','-v',str(site)+':/site','-v',str(root/'native/core-display-fix')+':/fix:ro','python:3.12-slim','python','/fix/apply.py'])
        assert 'TYPO3_PROPOSED_CORE_DISPLAY_FIX_APPLIED' in text,text
        print(text,end='',flush=True)
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
    text=run(base+['-e','TYPO3_TEST_CORE_DISPLAY_FIX='+str(int(args.test_core_display_fix)),image,'php','-d','disable_functions=mail','/app/native/form-probe.php'])
    assert 'TYPO3_FORM_COMPLETE 47 checks; no provider calls' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==47,text
    print(text,end='',flush=True)
    text=run(base+[image,'php','-d','disable_functions=mail','/app/native/controller-probe.php'])
    assert 'TYPO3_FORM_CONTROLLER_COMPLETE 20 checks; no provider calls' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==20,text
    print(text,end='',flush=True)
    text=run(base+[image,'php','-d','disable_functions=mail','/app/native/frontend-setup.php'])
    assert 'TYPO3_FRONTEND_SETUP_COMPLETE' in text,text
    print(text,end='',flush=True)
    http=name+'-http-server'
    workers.append(http)
    command=['docker','run','-d','--name',http,'--network','none',
             '-v',str(root)+':/app','-w','/app/.native-work/'+name,image,
             'php','-d','disable_functions=mail','-d','upload_max_filesize=2M',
             '-d','post_max_size=4M','-S','127.0.0.1:8080','-t','public']
    run(command)
    readiness="""require getcwd().'/vendor/autoload.php';
$c=new GuzzleHttp\\Client(['connect_timeout'=>1,'timeout'=>2,'proxy'=>'',
    'allow_redirects'=>false,'http_errors'=>false]);
$end=microtime(true)+30;
do {
    try {
        $r=$c->get('http://127.0.0.1:8080/');
        if ($r->getStatusCode()!==200) throw new RuntimeException('HTTP readiness status');
        echo 'TYPO3_HTTP_READY 200'; return;
    } catch (GuzzleHttp\\Exception\\ConnectException $e) { usleep(50000); }
} while (microtime(true)<$end);
throw new RuntimeException('HTTP readiness deadline');
"""
    text=run(['docker','exec',http,'php','-r',readiness],timeout=45)
    assert 'TYPO3_HTTP_READY 200' in text,text
    text=run(['docker','exec','-e',
              'TYPO3_TEST_CORE_DISPLAY_FIX='+str(int(args.test_core_display_fix)),
              http,'php','-d','disable_functions=mail',
              '/app/native/frontend-probe.php'],timeout=180)
    assert 'TYPO3_FRONTEND_HTTP_COMPLETE 24 checks; no provider calls' in text,text
    original,multi=text.split('TYPO3_FRONTEND_HTTP_COMPLETE 24 checks; no provider calls',1)
    mode='proposed-core-fix' if args.test_core_display_fix else 'stock'
    marker=f'TYPO3_FRONTEND_MULTIUPLOAD_COMPLETE 16 checks; mode={mode}; no provider calls'
    assert marker in multi,text
    assert sum(line.startswith('PASS ') for line in original.splitlines())==24,text
    assert sum(line.startswith('PASS ') for line in multi.splitlines())==16,text
    print(text,end='',flush=True)
    run(['docker','logs',http],timeout=10)
    run(['docker','stop','--time','3',http])
    run(['docker','rm',http])
    http=None
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
    print('TYPO3_NATIVE_AND_LIFECYCLE_COMPLETE 149 checks',flush=True)
finally:
    try:
        http_results.mkdir()
        if http is not None:
            result=subprocess.run(['docker','logs',http],capture_output=True,text=True,timeout=10)
            (http_results/'server.log').write_text(result.stdout+result.stderr)
        else:
            server=[c for c in commands if c['command'][:2]==['docker','logs']]
            if server:
                (http_results/'server.log').write_text(server[-1]['stdout']+server[-1]['stderr'])
        for filename in ['frontend-setup.json','frontend-entry-origin.json',
                         'frontend-capture.jsonl','frontend-observations.json',
                         'frontend-multiupload-setup.json',
                         'frontend-multiupload-observations.json']:
            if (site/filename).exists():shutil.copy2(site/filename,http_results/filename)
        if (site/'var/log').exists():shutil.copytree(site/'var/log',http_results/'typo3-log')
    finally:
        for worker in workers:
            subprocess.run(['docker','rm','-f',worker],capture_output=True,timeout=10)
        cleanup="from pathlib import Path; import shutil; "
        cleanup+="[(shutil.rmtree(p) if p.is_dir() and not p.is_symlink() else p.unlink()) "
        cleanup+="for p in Path('/site').iterdir()]"
        cleaner=name+'-cleanup'
        try:
            result=run(['docker','run','--name',cleaner,'--rm','--network','none',
                        '-v',str(site)+':/site','python:3.12-slim','python','-c',cleanup])
        finally:
            subprocess.run(['docker','rm','-f',cleaner],capture_output=True,timeout=10)
        site.rmdir()
        remaining=run(['docker','ps','-aq','--no-trunc']).splitlines()
        owned=run(['docker','ps','-a','--filter','name='+name,'--format','{{.Names}}'])
        preserved=all((site.parent/p).is_dir() for p in preexisting_sites)
        proof={'owned_site_absent':not site.exists(),'owned_containers_absent':owned=='',
               'preexisting_containers_preserved':set(preexisting_containers)<=set(remaining),
               'preexisting_sites_preserved':preserved,'run_prefix':name,
               'preexisting_container_ids':preexisting_containers}
        (http_results/'cleanup.json').write_text(json.dumps(proof,indent=2)+'\n')
        (http_results/'commands.json').write_text(json.dumps(commands,indent=2)+'\n')
        assert all(proof[key] for key in list(proof)[:4]),proof
        logs.append('TYPO3_NATIVE_CLEANUP_COMPLETE')
        (root/'.native-work'/(name+'-results.txt')).write_text('\n'.join(logs))
        print('TYPO3_NATIVE_CLEANUP_COMPLETE',flush=True)
