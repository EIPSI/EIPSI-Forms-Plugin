#!/usr/bin/env python3
"""Create a local installable ZIP from built sources; never publish a release."""
import argparse
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
RUNTIME_DIRS = ('admin', 'assets', 'build', 'includes', 'languages', 'lib', 'templates', 'examples')
EXCLUDED_UNUSED = {'includes/templates/progress-bar-component.php'}  # Unreferenced legacy template; invalid on 7.4 and 8.3.
ROOT_FILES = ('eipsi-forms.php', 'readme.txt', 'README.md', 'LICENSE')
EXTRA_FILES = ('exports/.htaccess', 'exports/index.php', 'scripts/migration-add-offset-columns.php', 'scripts/generate-eipsi-keyrings.php', 'docs/study-engine/server-keyrings.md', 'docs/setup-center.md')


def package(root, output):
    root = Path(root).resolve()
    output = Path(output).resolve()
    header = (root / 'eipsi-forms.php').read_text()
    version = re.search(r'^ \* Version: (\S+)$', header, re.M).group(1)
    blocks = sorted((root / 'build/blocks').glob('*/block.json'))
    expected = sorted((root / 'src/blocks').glob('*/block.json'))
    if not blocks or {p.parent.name for p in blocks} != {p.parent.name for p in expected}:
        raise ValueError('Missing block build. Run npm run build and check-build first.')
    for block in blocks:
        data = json.loads(block.read_text())
        for field in ('editorScript', 'script', 'viewScript', 'editorStyle', 'style', 'viewStyle'):
            refs = data.get(field, [])
            for ref in refs if isinstance(refs, list) else [refs]:
                if isinstance(ref, str) and ref.startswith('file:') and not (block.parent / ref[5:]).is_file():
                    raise ValueError('Missing compiled asset: ' + ref)
        if not (block.parent / 'index.asset.php').is_file():
            raise ValueError('Missing block dependency manifest')
    selected = []
    for name in RUNTIME_DIRS:
        selected.extend(p for p in (root / name).rglob('*') if p.is_file())
    selected.extend(root / name for name in ROOT_FILES + EXTRA_FILES)
    selected = [p for p in selected if p.relative_to(root).as_posix() not in EXCLUDED_UNUSED]
    for p in selected:
        if p.is_symlink() or not p.is_file():
            raise ValueError('Missing file or forbidden symlink: ' + str(p))
        if p.name.startswith(('eipsi-server-keyrings', 'wp-config')):
            raise ValueError('Private server configuration cannot be packaged: ' + str(p))
        if any(part.startswith('.env') or part in {'.git', 'node_modules', '.cache'} for part in p.relative_to(root).parts) or p.suffix in {'.log', '.pem', '.key', '.sql'}:
            raise ValueError('Unexpected private/development file: ' + str(p))
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        for p in sorted(set(selected)):
            archive.write(p, 'EIPSI-Forms-Plugin/' + p.relative_to(root).as_posix())
    return version, len(set(selected))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'dist/EIPSI-Forms-Plugin.zip')
    args = parser.parse_args()
    version, count = package(ROOT, args.output)
    print(f'Local package: {args.output} ({count} files; header version {version}); not a published release')


if __name__ == '__main__':
    main()
