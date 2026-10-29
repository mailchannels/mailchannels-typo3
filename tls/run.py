"""Run real cURL TLS probes on an internal Docker network, never the provider."""
from pathlib import Path
from datetime import datetime, timedelta, timezone
import json, subprocess, time, tempfile, sys, os, uuid
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
root=Path(__file__).resolve().parent
candidate=root.parent
prefix='mctypo3-'+uuid.uuid4().hex[:10]
network=prefix+'-net'
subprocess.run(['docker','network','create','--internal',network],check=True,capture_output=True)
try:
    with tempfile.TemporaryDirectory(prefix=prefix+'-tls-') as temporary:
        out=Path(temporary)
        os.chmod(out,0o777)  # Synthetic server evidence; no provider secrets.
        now=datetime.now(timezone.utc)
        def key():return rsa.generate_private_key(public_exponent=65537,key_size=2048)
        def ca(label):
            k=key();n=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,label)])
            c=x509.CertificateBuilder().subject_name(n).issuer_name(n).public_key(k.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-timedelta(days=2)).not_valid_after(now+timedelta(days=30)).add_extension(x509.BasicConstraints(ca=True,path_length=None),True).sign(k,hashes.SHA256())
            return k,c
        trusted_key,trusted_ca=ca('Isolated TYPO3 Fixture CA')
        untrusted_key,untrusted_ca=ca('Untrusted Fixture CA')
        (out/'ca.crt').write_bytes(trusted_ca.public_bytes(serialization.Encoding.PEM))
        for name in ['trusted','wrong-host','expired','untrusted']:
            k=key();ca_key,ca_cert=(untrusted_key,untrusted_ca) if name=='untrusted' else (trusted_key,trusted_ca)
            host='wrong.example.com' if name=='wrong-host' else 'api.mailchannels.net'
            cert=x509.CertificateBuilder().subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,host)])).issuer_name(ca_cert.subject).public_key(k.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-timedelta(days=2)).not_valid_after(now-timedelta(days=1) if name=='expired' else now+timedelta(days=7)).add_extension(x509.SubjectAlternativeName([x509.DNSName(host)]),False).sign(ca_key,hashes.SHA256())
            (out/(name+'.crt')).write_bytes(cert.public_bytes(serialization.Encoding.PEM))
            (out/(name+'.key')).write_bytes(k.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
        def run(args,**kwargs):return subprocess.run(args,text=True,capture_output=True,check=True,**kwargs)
        assert run(['docker','network','inspect','--format','{{.Internal}}',network]).stdout.strip()=='true'
        results=[]
        for scenario in ['trusted','wrong-host','expired','untrusted','redirect','stall','oversized','body-stall']:
            records=out/(scenario+'-requests.jsonl');records.unlink(missing_ok=True)
            name=prefix+'-tls'
            run(['docker','run','-d','--rm','--name',name,'--network',network,'--network-alias','api.mailchannels.net','-e','SCENARIO='+scenario,'-v',str(out)+':/fixtures','-v',str(root)+':/probe:ro','python:3.12-slim','python','/probe/server.py'])
            try:
                for attempt in range(100):
                    if 'READY' in run(['docker','logs',name]).stdout:break
                    time.sleep(.2)
                else:raise RuntimeError('TLS fixture did not become ready')
                result=run(['docker','run','--rm','--name',prefix+'-tls-client','--network',network,'-v',str(candidate)+':/candidate:ro','-v',str(out)+':/fixtures:ro','mailchannels-typo3-contract:php84','php','-d','disable_functions=mail','-d','curl.cainfo=/fixtures/ca.crt','/candidate/tls/client.php'],timeout=30)
                value=json.loads(result.stdout)
                observed=[json.loads(line) for line in records.read_text().splitlines()] if records.exists() else []
                assert value['accepted']==(scenario=='trusted'),(scenario,value)
                assert len(observed)==(0 if scenario in ['wrong-host','expired','untrusted'] else 1),(scenario,observed)
                assert all(r=={'path':'/tx/v1/send','key_matches':True,'recipient_matches':True} for r in observed)
                if scenario in ['stall','body-stall']:assert 14<=value['seconds']<=20,value
                assert value['outcome']==('accepted' if scenario=='trusted' else 'acceptance_unconfirmed'),(scenario,value)
                results.append(dict(scenario=scenario,**value,http_requests=len(observed)))
                print('PASS '+scenario,flush=True)
            finally:
                for container in [prefix+'-tls-client',name]:
                    subprocess.run(['docker','rm','-f',container],capture_output=True)
        print(json.dumps(results,sort_keys=True),flush=True)
        print('TLS_PROBE_COMPLETE',flush=True)
    print('TLS_FIXTURE_CLEANUP_COMPLETE',flush=True)
finally:
    for container in [prefix+'-tls-client',prefix+'-tls']:
        subprocess.run(['docker','rm','-f',container],capture_output=True)
    subprocess.run(['docker','network','rm',network],check=True,capture_output=True)
print('TYPO3_TLS_NETWORK_CLEANUP_COMPLETE',flush=True)
