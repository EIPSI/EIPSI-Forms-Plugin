"""Regressions for command output on cold CI runners; no Docker/DB required."""
import contextlib
import io
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import gate


class OutputTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.root.joinpath('node_modules').mkdir()
        self.root.joinpath('node_modules/.package-lock.json').write_text('{}')
        self.capture = contextlib.ExitStack()
        self.capture.enter_context(patch.object(gate, 'ROOT', self.root))
        self.capture.enter_context(contextlib.redirect_stdout(io.StringIO()))
        self.capture.enter_context(contextlib.redirect_stderr(io.StringIO()))
        self.runner = gate.Gate()

    def tearDown(self):
        self.capture.close()
        self.directory.cleanup()

    def version_command(self, version):
        # Same streams as a cold docker run: image diagnostics on stderr, version on stdout.
        return [sys.executable, '-c',
                f'import sys; print("Unable to find image locally\\nPulling from library/node", file=sys.stderr); print({version!r})']

    def test_cold_pull_diagnostics_do_not_change_version_output(self):
        expected = 'v' + gate.CONFIG['versions']['node']
        def node(stage, command, network=False):
            return self.runner.run(stage, self.version_command(expected))
        with patch.object(self.runner, 'node', side_effect=node):
            self.runner.dependencies()
        self.assertEqual((self.runner.logs / 'node-version-command.log').read_text(), expected + '\n')
        self.assertIn('Pulling from library/node', (self.runner.logs / 'node-version-command.stderr.log').read_text())

    def test_wrong_version_is_still_rejected_with_pull_diagnostics(self):
        def node(stage, command, network=False):
            return self.runner.run(stage, self.version_command('v0.0.0'))
        with patch.object(self.runner, 'node', side_effect=node), self.assertRaises(gate.Failure):
            self.runner.dependencies()

    def test_nonzero_command_keeps_exit_code_and_stderr_diagnostic(self):
        with self.assertRaises(gate.Failure) as error:
            self.runner.run('failed-command', [sys.executable, '-c',
                            'import sys; print("pull failed", file=sys.stderr); sys.exit(23)'])
        self.assertEqual(error.exception.code, 23)
        self.assertEqual((self.runner.logs / 'failed-command.stderr.log').read_text(), 'pull failed\n')


if __name__ == '__main__':
    unittest.main()
