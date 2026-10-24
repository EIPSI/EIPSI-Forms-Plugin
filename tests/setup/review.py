#!/usr/bin/env python3
"""Disposable loopback Setup review. Never accesses a normal WordPress installation."""
import argparse, json, os, re, shutil, subprocess, uuid
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
DIRECTORY = ROOT / '.cache/setup-review'
STATE = DIRECTORY / 'state.json'

def command(state, args, **kwargs):
    return subprocess.run(['docker','compose','-p',state['project'],'-f',str(ROOT/'tests/ci/compose.yml'),'-f',state['override'],*args],env=dict(os.environ,**state['environment']),check=True,text=True,**kwargs)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation',choices=['prepare','mail','external','managed','stop'])
    operation=parser.parse_args().operation
    DIRECTORY.mkdir(parents=True,exist_ok=True)
    if STATE.exists():
        state=json.loads(STATE.read_text())
        if not re.fullmatch(r'eipsi-setup-review-[a-f0-9]{10}',state['project']): raise ValueError('Unexpected fixture project')
        if operation=='stop':
            command(state,['down','--volumes','--remove-orphans']);shutil.rmtree(DIRECTORY)
            print('Only the recorded disposable Setup fixture and private review files were removed.');return
        if operation=='prepare':print('Setup fixture already recorded; use its private credentials or stop it first.');return
        script='/opt/eipsi-ci/tests/m0/bootstrap.php'
        if operation in ['external','managed']:
            mode='CONSTANT' if operation=='external' else 'STORE'
            code=f'require "{script}";update_option("eipsi_setup_fixture_mode","{mode}",false);ob_end_clean();'
            command(state,['exec','-T','wordpress','php','-r',code],stdout=subprocess.DEVNULL)
            print('Disposable source mode: '+mode+'. Reload Setup.');return
        code=f'require "{script}";require EIPSI_FORMS_PLUGIN_DIR."includes/study-engine/longitudinal.php";EIPSI\\StudyEngine\\Longitudinal\\Worker::tick();ob_end_clean();'
        command(state,['exec','-T','wordpress','php','-r',code],stdout=subprocess.DEVNULL)
        result=command(state,['exec','-T','wordpress','sh','-c','if test -f /tmp/eipsi-setup-review-mail.json; then cat /tmp/eipsi-setup-review-mail.json; else printf "[]"; fi'],capture_output=True)
        mail=json.loads(result.stdout);target=DIRECTORY/'mail.html'
        import html
        target.write_text('<!doctype html><meta charset="utf-8"><title>Disposable local emails</title>'+''.join('<h2>'+html.escape(m['subject'])+'</h2>'+m['message']+'<hr>' for m in mail));target.chmod(0o600)
        print('Captured local emails: '+str(target)+'; '+str(len(mail))+' messages. No delivery.');return
    if operation!='prepare':print('No Setup review fixture recorded.');return
    config=json.loads((ROOT/'tests/ci/gate.json').read_text())
    override=DIRECTORY/'compose.yml';override.write_text('services:\n  wordpress:\n    ports:\n      - "127.0.0.1:18226:80"\nnetworks:\n  default:\n    internal: false\n')
    state={'project':'eipsi-setup-review-'+uuid.uuid4().hex[:10],'override':str(override),'environment':{'EIPSI_CI_PLUGIN_ROOT':str(ROOT),'EIPSI_CI_TEST_ROOT':str(ROOT),'EIPSI_CI_WP_IMAGE':config['images']['wordpress'],'EIPSI_CI_DB_IMAGE':config['images']['mariadb'],'EIPSI_CI_SUBNET':'10.239.26.0/24'}}
    STATE.write_text(json.dumps(state,indent=2)+'\n');STATE.chmod(0o600)
    plugin='/var/www/html/wp-content/plugins/EIPSI-Forms-Plugin'
    try:
        command(state,['up','-d','--wait','--wait-timeout','120'],stdout=subprocess.DEVNULL)
        command(state,['exec','-T','wordpress','sh','-c','i=0; until test -f /var/www/html/wp-config.php; do i=$((i+1)); test "$i" -lt 60 || exit 1; sleep 1; done'])
        command(state,['exec','-T','wordpress','php',plugin+'/tests/m0/install.php'],stdout=subprocess.DEVNULL)
        result=command(state,['exec','-T','wordpress','php','/opt/eipsi-ci/tests/setup/install-review.php'],capture_output=True)
        credentials=DIRECTORY/'credentials.json';credentials.write_text(json.dumps(json.loads(result.stdout),indent=2)+'\n');credentials.chmod(0o600)
        command(state,['exec','-T','wordpress','sh','-c','mkdir -p /var/www/html/wp-content/mu-plugins; cp /opt/eipsi-ci/tests/setup/environment.php /var/www/html/wp-content/mu-plugins/eipsi-setup-config.php; cp /opt/eipsi-ci/tests/setup/review-mail.php /var/www/html/wp-content/mu-plugins/eipsi-setup-mail.php'])
        state['ready']=True;STATE.write_text(json.dumps(state,indent=2)+'\n')
        print('Human Setup review: http://localhost:18226/wp-admin/')
        print('Private disposable credentials and study links: '+str(credentials))
        print('Server keys remain missing until explicit human generation. Human review PENDING; mail captured locally.')
    except BaseException:
        command(state,['down','--volumes','--remove-orphans']);STATE.unlink(missing_ok=True);raise

if __name__=='__main__': main()
