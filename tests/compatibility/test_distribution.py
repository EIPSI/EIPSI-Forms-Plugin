import importlib.util
import json
from pathlib import Path
import re
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('packager', ROOT / 'scripts/package-plugin.py')
packager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packager)


class DistributionTests(unittest.TestCase):
    def test_metadata_agrees(self):
        header = (ROOT / 'eipsi-forms.php').read_text()
        readme = (ROOT / 'readme.txt').read_text()
        matrix = json.loads((ROOT / 'tests/compatibility/matrix.json').read_text())
        main_readme = (ROOT / 'README.md').read_text()
        self.assertIn('WordPress-' + matrix['wordpress_minimum'] + '%2B', main_readme)
        self.assertIn('PHP-' + matrix['php_minimum'] + '%2B', main_readme)
        for name, expected in [('Requires at least', matrix['wordpress_minimum']), ('Requires PHP', matrix['php_minimum']), ('Tested up to', max(matrix['cores'], key=lambda version: tuple(map(int, version.split('.')))))]:
            self.assertEqual(re.search(name + r': (\S+)', header).group(1), expected)
            self.assertEqual(re.search(name + r': (\S+)', readme).group(1), expected)

    def test_distribution_contains_runtime_not_development_or_data(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'plugin.zip'
            packager.package(ROOT, output)
            with zipfile.ZipFile(output) as archive:
                names = archive.namelist()
                self.assertIn('EIPSI-Forms-Plugin/build/blocks/form-container/index.asset.php', names)
                self.assertIn('EIPSI-Forms-Plugin/includes/i18n/content-defaults.json', names)
                self.assertIn('EIPSI-Forms-Plugin/scripts/generate-eipsi-keyrings.php', names)
                self.assertIn('EIPSI-Forms-Plugin/docs/study-engine/server-keyrings.md', names)
                self.assertIn('EIPSI-Forms-Plugin/docs/setup-center.md', names)
                self.assertIn('EIPSI-Forms-Plugin/includes/setup/SetupAdmin.php', names)
                self.assertIn('EIPSI-Forms-Plugin/includes/study-engine/configuration/SecretStore.php', names)
                self.assertFalse(any(Path(name).name.startswith(('eipsi-server-keyrings', 'wp-config', '.env')) for name in names))
                self.assertTrue(any('/examples/' in name for name in names))
                self.assertEqual([name for name in names if '/exports/' in name], ['EIPSI-Forms-Plugin/exports/.htaccess', 'EIPSI-Forms-Plugin/exports/index.php'])
                self.assertFalse(any('/tests/' in name or '/node_modules/' in name or '/.git/' in name or '/.cache/' in name or name.endswith('.log') for name in names))
                self.assertFalse(any(name.endswith('progress-bar-component.php') for name in names))

    def test_missing_build_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path/'eipsi-forms.php').write_text(' * Version: 1.0.0\n')
            with self.assertRaisesRegex(ValueError, 'Missing block build'):
                packager.package(path, path/'plugin.zip')

    def test_private_keyring_output_in_runtime_directory_is_rejected(self):
        private = ROOT / 'includes/eipsi-server-keyrings-packaging-test.php'
        with tempfile.TemporaryDirectory() as directory:
            created = False
            try:
                with private.open('x') as output:
                    created = True
                    output.write('<?php // Fake packaging sentinel, no secret.\n')
                with self.assertRaisesRegex(ValueError, 'Private server configuration'):
                    packager.package(ROOT, Path(directory) / 'plugin.zip')
            finally:
                if created:
                    private.unlink(missing_ok=True)
