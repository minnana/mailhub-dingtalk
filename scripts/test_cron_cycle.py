#!/usr/bin/env python3
"""Verify cycle ordering/error handling with a fake PHP executable, no real services."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

@unittest.skipUnless(os.name == 'posix', 'Requires the deployment POSIX shell')
class CronCycle(unittest.TestCase):
    def run_cycle(self, cron_exit=0, notify_exit=0):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            php = base / 'php'
            php.write_text('''#!/bin/sh
printf '%s\\n' "$2" >> "$FAKE_LOG"
case "$2" in
  /var/www/html/cron.php) exit "$FAKE_CRON_EXIT" ;;
  /var/www/html/custom_apps/mailhub_dingtalk/cli/notify-after-sync.php) exit "$FAKE_NOTIFY_EXIT" ;;
  *) exit 99 ;;
esac
''')
            php.chmod(0o700)
            env = dict(os.environ, PATH=str(base)+os.pathsep+os.environ['PATH'],
                       FAKE_LOG=str(base/'calls'), FAKE_CRON_EXIT=str(cron_exit),
                       FAKE_NOTIFY_EXIT=str(notify_exit))
            result = subprocess.run(['/bin/sh', str(ROOT/'templates/cron-cycle.sh')],
                                    env=env, text=True, capture_output=True)
            calls = (base/'calls').read_text().splitlines()
            self.assertEqual(calls, ['/var/www/html/cron.php',
                '/var/www/html/custom_apps/mailhub_dingtalk/cli/notify-after-sync.php'])
            return result

    def test_notification_follows_completed_sync(self):
        self.assertEqual(self.run_cycle().returncode, 0)

    def test_partial_cron_failure_still_attempts_notification_and_is_visible(self):
        result = self.run_cycle(cron_exit=17)
        self.assertEqual(result.returncode, 17)
        self.assertIn('Nextcloud cron failed', result.stderr)

    def test_notification_failure_is_not_reported_as_success(self):
        self.assertEqual(self.run_cycle(notify_exit=19).returncode, 19)

if __name__ == '__main__':
    unittest.main(verbosity=2)
