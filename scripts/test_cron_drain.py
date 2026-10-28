#!/usr/bin/env python3
"""Deterministic drain failure/race tests. No Docker, signals or real jobs."""
import importlib.util
import errno
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('cron_drain', Path(__file__).with_name('drain-cron.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FakeDocker:
    def __init__(self):
        self.state = dict(id='test-cron', pid=42, started='original', running=True, paused=False, restarting=False)
        self.rows = [[(42, 'T'), (43, 'S')], [(42, 'T')]]
        self.signals = []
        self.signal_targets = []
        self.bound_pid = 42
        self.closed = False
        self.fail_inspect = False
        self.fail_processes = False
        self.stops = 0
        self.verified = True
        self.fail_signal = None
        self.fail_stop = False
        self.replace = False
        self.stop_effective = True
        self.time = 0
    def find(self): return dict(self.state) if self.state['running'] else None
    def verify_daemon(self, state):
        if not self.verified: raise module.DrainError('unreviewed daemon')
    def inspect(self, cid):
        if self.fail_inspect and self.signals:
            raise module.DrainError('Docker API unavailable')
        if self.replace and self.signals:
            self.state['pid'] = 99
        return dict(self.state)
    def signal(self, cid, value):
        self.signals.append(value)
        self.signal_targets.append((self.bound_pid, value))
        if self.fail_signal == value: raise module.DrainError('uncertain signal')
    def processes(self, cid):
        if self.fail_processes: raise module.DrainError('Docker top unavailable')
        return self.rows.pop(0) if len(self.rows) > 1 else self.rows[0]
    def stop(self, cid):
        self.stops += 1
        if self.fail_stop: raise module.DrainError('stop failed')
        if self.stop_effective: self.state['running'] = False
    def sleep(self, seconds): self.time += seconds
    def close(self): self.closed = True
    def run(self, timeout=3): return module.drain(self, timeout, lambda: self.time, self.sleep)


class DrainTests(unittest.TestCase):
    def test_waits_for_child_before_stopping(self):
        fake = FakeDocker(); self.assertTrue(fake.run())
        self.assertEqual(fake.time, 1); self.assertEqual(fake.signals, ['STOP']); self.assertEqual(fake.stops, 1)
    def test_stopped_container_noop(self):
        fake = FakeDocker(); fake.state['running'] = False
        self.assertFalse(fake.run()); self.assertEqual(fake.signals, [])
    def test_timeout_resumes_without_killing_job(self):
        fake = FakeDocker(); fake.rows = [[(42, 'T'), (43, 'S')]]
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals, ['STOP', 'CONT']); self.assertEqual(fake.stops, 0)
    def test_waits_for_non_php_child_too(self):
        fake = FakeDocker(); fake.rows = [[(42, 'T'), (77, 'R')]]
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.stops, 0)
    def test_stopped_child_is_still_active(self):
        fake = FakeDocker(); fake.rows = [[(42, 'T'), (43, 'T')]]
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.stops, 0)
    def test_zombie_is_completed_work(self):
        fake = FakeDocker(); fake.rows = [[(42, 'T'), (43, 'Z')]]
        self.assertTrue(fake.run())
    def test_parent_must_really_be_stopped(self):
        fake = FakeDocker(); fake.rows = [[(42, 'S')]]
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.stops, 0)
    def test_parent_missing_refuses_stop(self):
        fake = FakeDocker(); fake.rows = [[(43, 'Z')]]
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals[-1], 'CONT'); self.assertEqual(fake.stops, 0)
    def test_replacement_container_not_signalled_or_stopped(self):
        fake = FakeDocker(); fake.replace = True
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signal_targets, [(42, 'STOP'), (42, 'CONT')])
        self.assertEqual(fake.state['pid'], 99); self.assertEqual(fake.stops, 0)
    def test_docker_inspect_failure_cannot_block_pidfd_resume(self):
        fake = FakeDocker(); fake.fail_inspect = True
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signal_targets, [(42, 'STOP'), (42, 'CONT')])
        self.assertEqual(fake.stops, 0); self.assertTrue(fake.closed)
    def test_docker_top_failure_still_resumes_original_process(self):
        fake = FakeDocker(); fake.fail_processes = True
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals, ['STOP', 'CONT']); self.assertTrue(fake.closed)
    def test_original_process_exit_is_harmless_when_resuming(self):
        backend = module.Docker(); backend.original = {'id': 'test-cron'}; backend.pidfd = 81
        with patch.object(module.signal, 'SIGCONT', 18, create=True), patch.object(module.signal, 'SIGSTOP', 19, create=True), patch.object(module.signal, 'pidfd_send_signal', create=True, side_effect=ProcessLookupError(errno.ESRCH, 'gone')) as send:
            backend.signal('test-cron', 'CONT')
            send.assert_called_once_with(81, 18)
    def test_resume_does_not_hide_permission_or_descriptor_errors(self):
        backend = module.Docker(); backend.original = {'id': 'test-cron'}; backend.pidfd = 81
        with patch.object(module.signal, 'SIGCONT', 18, create=True), patch.object(module.signal, 'SIGSTOP', 19, create=True), patch.object(module.signal, 'pidfd_send_signal', create=True, side_effect=PermissionError(errno.EPERM, 'denied')):
            with self.assertRaises(module.DrainError): backend.signal('test-cron', 'CONT')
    def test_original_process_exit_before_pause_remains_a_failure(self):
        backend = module.Docker(); backend.original = {'id': 'test-cron'}; backend.pidfd = 81
        with patch.object(module.signal, 'SIGCONT', 18, create=True), patch.object(module.signal, 'SIGSTOP', 19, create=True), patch.object(module.signal, 'pidfd_send_signal', create=True, side_effect=ProcessLookupError(errno.ESRCH, 'gone')):
            with self.assertRaises(module.DrainError): backend.signal('test-cron', 'STOP')
    def test_uncertain_stop_signal_still_resumes(self):
        fake = FakeDocker(); fake.fail_signal = 'STOP'
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals, ['STOP', 'CONT']); self.assertEqual(fake.stops, 0)
    def test_stop_failure_resumes_scheduler(self):
        fake = FakeDocker(); fake.rows = [[(42, 'T')]]; fake.fail_stop = True
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals[-1], 'CONT')
    def test_stop_success_requires_actual_container_stopped(self):
        fake = FakeDocker(); fake.rows = [[(42, 'T')]]; fake.stop_effective = False
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals[-1], 'CONT')
    def test_paused_container_is_rejected(self):
        fake = FakeDocker(); fake.state['paused'] = True
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals, [])
    def test_restarting_container_is_rejected(self):
        fake = FakeDocker(); fake.state['restarting'] = True
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals, [])
    def test_unreviewed_pid_one_is_rejected(self):
        fake = FakeDocker(); fake.verified = False
        with self.assertRaises(module.DrainError): fake.run()
        self.assertEqual(fake.signals, [])


if __name__ == '__main__': unittest.main(verbosity=2)
