#!/usr/bin/env python3
"""Prepare/stop one loopback-only human RBAC fixture; never touches the main WordPress."""
import argparse,json,os,re,subprocess,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
DIRECTORY=ROOT/'.cache/rbac-review'
STATE=DIRECTORY/'state.json'

def command(state,args,**kwargs):
    env=dict(os.environ,**state['environment'])
    return subprocess.run(['docker','compose','-p',state['project'],'-f',str(ROOT/'tests/ci/compose.yml'),'-f',state['override'],*args],env=env,check=True,text=True,**kwargs)

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('operation',choices=['prepare','stop']);a=parser.parse_args()
    DIRECTORY.mkdir(parents=True,exist_ok=True)
    if a.operation=='stop':
        if not STATE.exists(): print('No RBAC review fixture recorded.');return
        state=json.loads(STATE.read_text())
        if not re.fullmatch(r'eipsi-rbac-review-[a-f0-9]{10}',state['project']):raise ValueError('Unexpected project')
        command(state,['down','--volumes','--remove-orphans']);STATE.unlink()
        for f in ['credentials.json','data.json']:(DIRECTORY/f).unlink(missing_ok=True)
        print('Only the recorded disposable RBAC fixture was removed.');return
    if STATE.exists():
        state=json.loads(STATE.read_text())
        print('Review fixture already recorded: '+state['project']+'; use its credentials or stop before recreating.');return
    config=json.loads((ROOT/'tests/ci/gate.json').read_text())
    override=DIRECTORY/'compose.yml';override.write_text('services:\n  wordpress:\n    ports:\n      - "127.0.0.1:18225:80"\nnetworks:\n  default:\n    internal: false\n')
    state={'project':'eipsi-rbac-review-'+uuid.uuid4().hex[:10],'override':str(override),'environment':{'EIPSI_CI_PLUGIN_ROOT':str(ROOT),'EIPSI_CI_TEST_ROOT':str(ROOT),'EIPSI_CI_WP_IMAGE':config['images']['wordpress'],'EIPSI_CI_DB_IMAGE':config['images']['mariadb'],'EIPSI_CI_SUBNET':'10.239.23.0/24'}}
    STATE.write_text(json.dumps(state,indent=2)+'\n');STATE.chmod(0o600)
    plugin='/var/www/html/wp-content/plugins/EIPSI-Forms-Plugin'
    try:
        command(state,['up','-d','--wait','--wait-timeout','120'],stdout=subprocess.DEVNULL)
        command(state,['exec','-T','wordpress','sh','-c','i=0; until test -f /var/www/html/wp-config.php; do i=$((i+1)); test "$i" -lt 60 || exit 1; sleep 1; done'])
        command(state,['exec','-T','wordpress','php',plugin+'/tests/m0/install.php'],stdout=subprocess.DEVNULL)
        command(state,['exec','-T','wordpress','sh','-c','mkdir -p /var/www/html/wp-content/mu-plugins; cp "$1" /var/www/html/wp-content/mu-plugins/eipsi-rbac-config.php; printf "<?php add_filter(\'pre_wp_mail\',\'__return_true\',PHP_INT_MAX);\\n" > /var/www/html/wp-content/mu-plugins/eipsi-rbac-mail.php','fixture',plugin+'/tests/rbac/public-config.php'])
        result=command(state,['exec','-T','-e','EIPSI_RBAC_REVIEW_URL=http://localhost:18225','wordpress','php',plugin+'/tests/rbac/install-review.php'],capture_output=True)
        credentials=json.loads(result.stdout);target=DIRECTORY/'credentials.json';target.write_text(json.dumps(credentials,indent=2)+'\n');target.chmod(0o600)
        result=command(state,['exec','-T','wordpress','php',plugin+'/tests/data1/install-download-review.php'],capture_output=True)
        data=json.loads(result.stdout);(DIRECTORY/'data.json').write_text(json.dumps(data,indent=2)+'\n');(DIRECTORY/'data.json').chmod(0o600)
        state['ready']=True;STATE.write_text(json.dumps(state,indent=2)+'\n')
        print('Human RBAC review: http://localhost:18225/wp-admin/')
        print('Private disposable credentials: '+str(target))
        print('Synthetic study/data: '+str(DIRECTORY/'data.json'))
        print('Pending human review; no external email delivery.')
    except BaseException:
        command(state,['down','--volumes','--remove-orphans']);STATE.unlink(missing_ok=True);raise

if __name__=='__main__':main()
