#!/usr/bin/env python3
"""One local/Actions gate. Existing suites remain unchanged; fixtures are disposable."""
import argparse
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
CONFIG = json.loads((ROOT / 'tests/ci/gate.json').read_text())
PLUGIN = '/var/www/html/wp-content/plugins/EIPSI-Forms-Plugin'


class Failure(Exception):
    def __init__(self, code=1):
        self.code = code if code > 0 else 1


class Gate:
    def __init__(self):
        self.logs = ROOT / '.cache/ci' / (time.strftime('%Y%m%d-%H%M%S') + '-' + uuid.uuid4().hex[:8])
        self.logs.mkdir(parents=True)
        self.results = []
        self.started = time.monotonic()

    def run(self, stage, command, env=None, timeout=600):
        print(f'RUN {stage}: {shlex.join(command)}', flush=True)
        started = time.monotonic()
        log = self.logs / (stage + '.log')
        stderr_log = self.logs / (stage + '.stderr.log')
        try:
            with log.open('w') as output, stderr_log.open('w') as errors:
                result = subprocess.run(command, cwd=ROOT, env=env, stdout=output,
                                        stderr=errors, timeout=timeout)
            code = result.returncode
        except subprocess.TimeoutExpired:
            code = 124
        except OSError as error:
            log.write_text(str(error))
            code = 127
        self.results.append({'stage': stage, 'exit_code': code,
                             'seconds': round(time.monotonic() - started, 2),
                             'command': command, 'log': str(log.relative_to(ROOT)),
                             'stderr_log': str(stderr_log.relative_to(ROOT))})
        text = log.read_text(errors='replace')
        if code:
            print(f'FAIL {stage}: exit {code}; log {log}', file=sys.stderr)
            errors = stderr_log.read_text(errors='replace') if stderr_log.exists() else ''
            print('\n'.join((text + '\n' + errors).splitlines()[-60:]), file=sys.stderr)
            raise Failure(code)
        print(f'PASS {stage} ({self.results[-1]["seconds"]}s)', flush=True)
        return text

    def check(self, stage, condition, message):
        if not condition:
            print(f'FAIL {stage}: {message}', file=sys.stderr)
            self.results.append({'stage': stage, 'exit_code': 1, 'error': message})
            raise Failure()

    def count(self, stage, output, expected):
        summaries = re.findall(r'(\d+) (?:consumer )?tests, (\d+) failures', output)
        self.check(stage, bool(summaries) and tuple(map(int, summaries[-1])) == (expected, 0),
                   f'Expected {expected} tests, 0 failures; suite summary missing or changed')

    def node(self, stage, command, network=False):
        args = ['docker', 'run', '--rm', '--user', f'{os.getuid()}:{os.getgid()}',
                '-e', 'HOME=/tmp', '-v', f'{ROOT}:/app', '-w', '/app']
        if not network:
            args += ['--network', 'none']
        return self.run(stage, args + [CONFIG['images']['node']] + command)

    def dependencies(self):
        # Docker emits image-pull progress on stderr on a cold runner.
        # Only stdout is the machine-readable output of node --version.
        version = self.node('node-version-command', ['node', '--version']).strip()
        expected = 'v' + CONFIG['versions']['node']
        self.check('node-version', version == expected,
                   f'Expected Node {expected}, observed {version!r}')
        print(f'PASS node-version ({version})', flush=True)
        if not (ROOT / 'node_modules/.package-lock.json').is_file():
            self.node('npm-ci', ['npm', 'ci', '--no-audit', '--no-fund'], network=True)

    def js(self):
        for script, count in CONFIG['js'].items():
            stage = 'js-' + script.removeprefix('tests/').replace('/', '-').removesuffix('.js')
            self.count(stage, self.node(stage, ['node', script]), count)
        print(f'JS: {sum(CONFIG["js"].values())} tests, 0 failures', flush=True)

    def build(self):
        self.node('build', ['npm', 'run', 'build'])
        self.node('build-artifacts', ['node', 'tests/ci/check-build.js'])

    def wordpress(self, mode):
        # This unique project is the only target of teardown; never touch older fixtures.
        project = 'eipsi-t0-' + mode + '-' + uuid.uuid4().hex[:10]
        env = dict(os.environ, EIPSI_CI_PLUGIN_ROOT=str(ROOT),
                   EIPSI_CI_WP_IMAGE=CONFIG['images']['wordpress'],
                   EIPSI_CI_DB_IMAGE=CONFIG['images']['mariadb'])
        compose = ['docker', 'compose', '-p', project, '-f', str(ROOT / 'tests/ci/compose.yml')]
        def call(stage, args):
            return self.run(mode + '-' + stage, compose + args, env=env)
        def php(stage, script, extra=None):
            return call(stage, ['exec', '-T'] + (extra or []) + ['wordpress', 'php', script])
        failed = False
        try:
            call('up', ['up', '-d', '--wait', '--wait-timeout', '120'])
            # Apache may be running while the official entrypoint is still copying core.
            call('ready', ['exec', '-T', 'wordpress', 'sh', '-c',
                 'i=0; until test -f /var/www/html/wp-config.php && test -f /var/www/html/wp-includes/version.php; do i=$((i+1)); test "$i" -lt 60 || exit 1; sleep 1; done'])
            php('install', PLUGIN + '/tests/m0/install.php')
            call('versions', ['exec', '-T', 'wordpress', 'php', '-r',
                 'require "/var/www/html/wp-includes/version.php";'
                 f'if(PHP_VERSION!=="{CONFIG["versions"]["php"]}"||$wp_version!=="{CONFIG["versions"]["wordpress"]}")exit(1);'
                 'echo "PHP ".PHP_VERSION." WordPress ".$wp_version."\\n";'])
            db_version = call('db-version', ['exec', '-T', 'db', 'mariadb', '-uroot',
                              '-pm0-root-isolated', '-Nse', 'SELECT VERSION()'])
            self.check('db-version', db_version.strip().startswith(CONFIG['versions']['mariadb'] + '-'),
                       'Unexpected MariaDB version')
            if mode == 'php':
                self.php_suites(call, php)
            else:
                self.count('clean-smoke', php('checks', PLUGIN + '/tests/ci/smoke.php'), 6)
                php('lifecycle', PLUGIN + '/tests/m7/lifecycle.php')
                # Same six assertions after reactivation; do not count them twice.
                self.count('clean-smoke-after-lifecycle', php('checks-after-lifecycle', PLUGIN + '/tests/ci/smoke.php'), 6)
        except BaseException:
            failed = True
            try:
                call('container-logs', ['logs', '--no-color', '--tail', '60'])
            except Failure:
                pass
            raise
        finally:
            try:
                call('down', ['down', '--volumes', '--remove-orphans'])
            except Failure:
                if not failed:
                    raise

    def php_suites(self, call, php):
        call('auxiliary-db', ['exec', '-T', 'db', 'mariadb', '-uroot', '-pm0-root-isolated', '-e',
             "CREATE DATABASE m6_external; CREATE DATABASE m8_external; "
             "GRANT ALL ON m6_external.* TO 'm0'@'%'; GRANT ALL ON m8_external.* TO 'm0'@'%'; "
             "CREATE USER 'm8_noalter'@'%' IDENTIFIED BY 'm8-noalter'; "
             "GRANT SELECT,INSERT,UPDATE,DELETE ON m0.* TO 'm8_noalter'@'%';"])
        call('copy', ['exec', '-T', 'wordpress', 'bash', '-o', 'pipefail', '-c',
             f'mkdir /tmp/eipsi-ci-tests; tar -C {PLUGIN} --exclude=node_modules --exclude=.git --exclude=.cache -cf - . | tar -C /tmp/eipsi-ci-tests -xf -'])
        call('export-permissions', ['exec', '-T', 'wordpress', 'chown', 'www-data:www-data', PLUGIN + '/exports'])
        call('export-probe', ['exec', '-T', 'wordpress', 'php', '-r',
             f'define("DOING_CRON",true);require "{PLUGIN}/tests/m0/bootstrap.php";'
             '$p=EIPSI_Export_File_Service::directory()."/m6-public-probe.csv";'
             'if(file_exists($p))exit(1);'
             f'file_put_contents($p,file_get_contents("{PLUGIN}/tests/m6/fixtures/m6-public-probe.csv"));ob_end_flush();'])
        for name, count in CONFIG['php'].items():
            base = '/tmp/eipsi-ci-tests/tests/'
            if name == 'debug-off':
                output = php(name, base + 'm1/debug-off.php', ['-e', 'WORDPRESS_DEBUG=0'])
            elif name == 'p0':
                output = call(name, ['exec', '-T', 'wordpress', 'php', base + 'run-p0.php', '--integration'])
            else:
                output = php(name, base + 'run-' + name + '.php')
            self.count('php-' + name, output, count)
        output = php('rct-strict', PLUGIN + '/tests/run-s4.php', ['-e', 'EIPSI_S4_EXPECT_RCT_DENIAL=1'])
        self.count('rct-strict', output, 1)
        print(f'PHP: {sum(CONFIG["php"].values())} tests, 0 failures; RCT strict 1/1', flush=True)

    def finish(self, code):
        summary = {'exit_code': code, 'seconds': round(time.monotonic() - self.started, 2),
                   'stages': self.results}
        (self.logs / 'summary.json').write_text(json.dumps(summary, indent=2) + '\n')
        print(f'{"PASS" if code == 0 else "FAIL"} gate: exit {code}, {summary["seconds"]}s; logs {self.logs}', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', nargs='?', default='full', choices=['fast', 'full', 'php', 'js', 'build', 'smoke'])
    mode = parser.parse_args().mode
    gate = Gate()
    code = 0
    try:
        gate.run('harness-tests', ['python3', '-B', '-m', 'unittest', 'discover',
                                  '-s', 'tests/ci', '-p', 'test_gate.py'])
        gate.run('docker', ['docker', 'info', '--format', '{{.ServerVersion}}'])
        gate.dependencies()
        if mode in ('fast', 'full', 'js'):
            gate.js()
        # build/ is ignored by Git; PHP and smoke need real generated block assets.
        if mode in ('fast', 'full', 'build', 'php', 'smoke'):
            gate.build()
        if mode in ('fast', 'full', 'php'):
            gate.wordpress('php')
        if mode in ('full', 'smoke'):
            gate.wordpress('smoke')
    except Failure as error:
        code = error.code
    except KeyboardInterrupt:
        code = 130
    except Exception as error:
        code = 1
        print(f'FAIL gate infrastructure: {type(error).__name__}: {error}', file=sys.stderr)
    finally:
        gate.finish(code)
    return code


if __name__ == '__main__':
    sys.exit(main())
