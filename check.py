from pathlib import Path
import subprocess
root=Path(__file__).resolve().parent
base=['docker','run','--rm','--network','none','-v',str(root)+':/app:ro','-w','/app/contract','mailchannels-typo3-contract:php84','php','-d','disable_functions=mail,exec,shell_exec,system,passthru,proc_open']
for script,count,sentinel in [('probe.php',18,'TYPO3_TRANSPORT_CONTRACT_COMPLETE'),('envelope-probe.php',30,'TYPO3_ENVELOPE_COMPLETE'),('body-probe.php',26,'TYPO3_BODY_COMPLETE'),('payload-probe.php',23,'TYPO3_PAYLOAD_COMPLETE'),('transport-probe.php',29,'TYPO3_TRANSPORT_COMPLETE'),('configuration-probe.php',19,'TYPO3_CONFIGURATION_COMPLETE'),('package-probe.php',10,'TYPO3_PACKAGE_COMPLETE')]:
 result=subprocess.run(base+[script],capture_output=True,text=True,check=True)
 print(result.stdout,end='')
 assert f'{sentinel} {count} checks; no provider calls' in result.stdout,result.stdout+result.stderr
 assert sum(line.startswith('PASS ') for line in result.stdout.splitlines())==count,result.stdout+result.stderr
print('TYPO3_CONTRACTS_COMPLETE 155 checks')
