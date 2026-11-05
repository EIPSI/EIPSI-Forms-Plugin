#!/usr/bin/env python3
"""Freeze approved files before creating a separately instrumented, disposable copy."""
from pathlib import Path
import hashlib,importlib.util,json,shutil,sys
ROOT=Path(__file__).resolve().parents[2];cache=ROOT/'.cache/perf1';dest=cache/'instrumented-plugin';out=Path(sys.argv[1]);out.mkdir(parents=True,exist_ok=True)
if dest.exists():shutil.rmtree(dest)
dest.mkdir(parents=True)
spec=importlib.util.spec_from_file_location('pack',ROOT/'scripts/package-plugin.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
for name in m.RUNTIME_DIRS:
 shutil.copytree(ROOT/name,dest/name,dirs_exist_ok=True)
for name in m.ROOT_FILES+m.EXTRA_FILES:
 p=dest/name;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/name,p)
for name in m.EXCLUDED_UNUSED:
 p=dest/name
 if p.exists():p.unlink()
(out/'copy-manifest-before.json').write_text(json.dumps({str(p.relative_to(dest)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(dest.rglob('*')) if p.is_file()},indent=2))
print(dest)
