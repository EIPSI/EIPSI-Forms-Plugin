#!/usr/bin/env python3
"""Syntax-check exactly the PHP shipped in the local package on declared PHP 7.4."""
import json
from pathlib import Path
import subprocess
import zipfile
import tempfile

ROOT = Path(__file__).resolve().parents[2]
matrix = json.loads((ROOT / 'tests/compatibility/matrix.json').read_text())
archive = ROOT / '.cache/compatibility/EIPSI-Forms-Plugin.zip'
with zipfile.ZipFile(archive) as package:
    files = [name.split('/', 1)[1] for name in package.namelist() if name.endswith('.php')]
code = '''$files=json_decode(stream_get_contents(STDIN),true);$bad=0;foreach($files as $file){exec(PHP_BINARY.' -l '.escapeshellarg('/app/'.$file).' 2>&1',$out,$status);if($status){echo implode("\\n",$out)."\\n";$bad++;}$out=array();}echo count($files)." packaged PHP files; ".$bad." syntax failures on ".PHP_VERSION."\\n";exit($bad?1:0);'''
with tempfile.TemporaryDirectory(prefix='eipsi-package-lint-') as directory:
    target = Path(directory)
    with zipfile.ZipFile(archive) as package:
        for name in files:
            path = (target / name).resolve()
            if not path.is_relative_to(target):
                raise ValueError('Unsafe PHP archive path')
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(package.read('EIPSI-Forms-Plugin/' + name))
    result = subprocess.run(['docker', 'run', '--rm', '-i', '--network', 'none', '-v', str(target)+':/app:ro', '-w', '/app', matrix['php_lint_image'], 'php', '-r', code], input=json.dumps(files), text=True)
raise SystemExit(result.returncode)
