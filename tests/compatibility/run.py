#!/usr/bin/env python3
"""Pinned official core matrix: fresh install, schema, admin/runtime smoke, lifecycle."""
import hashlib
import json
from pathlib import Path
import sys
import urllib.request
import zipfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tests/ci'))
import gate


def main():
    matrix = json.loads((ROOT / 'tests/compatibility/matrix.json').read_text())
    versions = sys.argv[1:] or list(matrix['cores'])
    runner = gate.Gate()
    code = 0
    try:
        runner.run('docker', ['docker', 'info', '--format', '{{.ServerVersion}}'])
        for version in versions:
            entry = matrix['cores'][version]
            path = ROOT / '.cache/compatibility' / ('wordpress-' + version + '.tar.gz')
            path.parent.mkdir(parents=True, exist_ok=True)
            if not path.exists():
                with urllib.request.urlopen(entry['url'], timeout=90) as response:
                    payload = response.read()
                if hashlib.sha256(payload).hexdigest() != entry['sha256']:
                    raise ValueError('Official core checksum mismatch')
                path.write_bytes(payload)
            if hashlib.sha256(path.read_bytes()).hexdigest() != entry['sha256']:
                raise ValueError('Cached core checksum mismatch')
            print('CORE ' + version + ' SHA256 verified', flush=True)
            runner.wordpress('smoke', core_version=version)
        archive = ROOT / '.cache/compatibility/EIPSI-Forms-Plugin.zip'
        if archive.exists():
            target = ROOT / '.cache/compatibility/package'
            import shutil
            if target.exists():
                shutil.rmtree(target)
            with zipfile.ZipFile(archive) as package:
                for info in package.infolist():
                    dest = (target / info.filename).resolve()
                    if not dest.is_relative_to(target.resolve()):
                        raise ValueError('Unsafe archive path')
                package.extractall(target)
            print('PACKAGE installed without development/test files', flush=True)
            runner.wordpress('smoke', core_version='7.1.3', package_root=target / 'EIPSI-Forms-Plugin')
    except Exception as error:
        code = 1
        print('FAIL compatibility: ' + str(error), file=sys.stderr)
    finally:
        runner.finish(code)
    return code


if __name__ == '__main__':
    sys.exit(main())
