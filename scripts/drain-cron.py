#!/usr/bin/env python3
"""Stop only Mailhub's scheduler after its existing child work finishes.

Works with older deployments too: no new in-container lock is required.
SIGSTOP targets only the verified crond PID 1, never the whole container.
No Nextcloud database, mail, notification cursor or reservation is modified.
"""
import argparse
import json
import os
from pathlib import Path
import signal
import stat
import subprocess
import sys
import time


class DrainError(RuntimeError):
    pass


class Docker:
    def run(self, *args):
        try:
            return subprocess.run(['docker', *args], check=True, text=True,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                  timeout=30).stdout
        except (subprocess.SubprocessError, OSError) as exc:
            raise DrainError('Docker operation failed; no active job will be forcibly stopped.') from None

    def inspect(self, container):
        try:
            value = json.loads(self.run('inspect', container))
            if len(value) != 1:
                raise ValueError()
            obj = value[0]
            labels = obj['Config']['Labels']
            if labels.get('com.docker.compose.project') != 'mailhub' or labels.get('com.docker.compose.service') != 'cron':
                raise ValueError()
            state = obj['State']
            if type(state['Pid']) is not int or state['Pid'] < 0:
                raise ValueError()
            return {'id': obj['Id'], 'pid': state['Pid'], 'started': state['StartedAt'],
                    'running': state['Running'], 'paused': state['Paused'], 'restarting': state['Restarting']}
        except (KeyError, ValueError, TypeError, IndexError):
            raise DrainError('Cannot verify the Mailhub cron container identity.') from None

    def find(self):
        ids = self.run('ps', '-aq', '--filter', 'label=com.docker.compose.project=mailhub',
                       '--filter', 'label=com.docker.compose.service=cron').split()
        running = [s for s in (self.inspect(c) for c in ids) if s['running']]
        if len(running) > 1:
            raise DrainError('Multiple Mailhub cron containers are running; inspect before maintenance.')
        return running[0] if running else None

    def verify_daemon(self, snapshot):
        self.pidfd = None
        try:
            # A pidfd pins this kernel process across PID reuse and container
            # restarts. Never send STOP/CONT by a reusable PID or container ID.
            self.pidfd = os.pidfd_open(snapshot['pid'])
            args = Path(f"/proc/{snapshot['pid']}/cmdline").read_bytes().rstrip(b'\0').split(b'\0')
            args = [a.decode('utf-8') for a in args]
            name = os.path.basename(args[0])
            daemon = args[1:] if name == 'busybox' else args if name == 'crond' else []
            if name == 'crond':
                daemon[0] = 'crond'
            if daemon != ['crond', '-f', '-L', '/dev/stdout']:
                raise ValueError()
            if not same_process(snapshot, self.inspect(snapshot['id'])):
                raise ValueError()
            if not hasattr(signal, 'pidfd_send_signal'):
                raise ValueError()
            self.original = dict(snapshot)
        except (OSError, UnicodeError, ValueError, IndexError, AttributeError, DrainError):
            self.close()
            raise DrainError('Cron PID 1 is not the reviewed BusyBox scheduler; refusing to signal it.') from None

    def signal(self, container, value):
        if container != self.original['id'] or value not in ('STOP', 'CONT'):
            raise DrainError('Unexpected scheduler signal request.')
        try:
            signal.pidfd_send_signal(self.pidfd, signal.SIGSTOP if value == 'STOP' else signal.SIGCONT)
        except ProcessLookupError:
            # The pidfd refers to the original process even if a replacement
            # container is now running. Nothing remains to resume after exit.
            if value == 'CONT':
                return
            raise DrainError('The original scheduler exited before it could be paused.') from None
        except OSError:
            raise DrainError('Cannot signal the original scheduler process.') from None

    def close(self):
        if self.pidfd is not None:
            os.close(self.pidfd)
            self.pidfd = None

    def processes(self, container):
        output = self.run('top', container, '-eo', 'pid,ppid,stat,comm')
        lines = output.splitlines()
        if not lines or lines[0].split()[:3] != ['PID', 'PPID', 'STAT']:
            raise DrainError('Cannot read scheduler processes safely.')
        rows = []
        for line in lines[1:]:
            parts = line.split()
            if len(parts) != 4 or not parts[0].isdigit() or not parts[1].isdigit() or not parts[2]:
                raise DrainError('Unexpected process inventory; refusing to stop cron.')
            rows.append((int(parts[0]), parts[2]))
        return rows

    def stop(self, container):
        # PID 1 is stopped and all active children have exited. TERM cannot wake
        # a stopped daemon; Docker's short timeout kills only an idle scheduler.
        if not same_process(self.original, self.inspect(container)):
            raise DrainError('Scheduler changed before stop; refusing maintenance.')
        self.run('stop', '--timeout', '2', container)


def same_process(before, after):
    return (after['running'] and before['id'] == after['id']
            and before['pid'] == after['pid'] and before['started'] == after['started'])


def drain(backend, timeout=900, clock=time.monotonic, sleep=time.sleep):
    before = backend.find()
    if before is None:
        return False
    if before['paused'] or before['restarting'] or before['pid'] <= 1:
        raise DrainError('Cron is paused or restarting; resolve that state before maintenance.')
    backend.verify_daemon(before)
    deadline = clock() + timeout
    paused_by_us = False
    stopped = False
    try:
        # Set before the request: a Docker timeout can occur after STOP committed.
        paused_by_us = True
        backend.signal(before['id'], 'STOP')
        while True:
            current = backend.inspect(before['id'])
            if not same_process(before, current) or current['paused'] or current['restarting']:
                raise DrainError('Cron container changed during drain; refusing to stop a different process.')
            rows = backend.processes(before['id'])
            parent = [state for pid, state in rows if pid == before['pid']]
            if len(parent) != 1:
                raise DrainError('Scheduler PID is missing from process inventory.')
            active = [pid for pid, state in rows if pid != before['pid'] and not state.startswith('Z')]
            if parent[0].startswith(('T', 't')) and not active:
                backend.stop(before['id'])
                if backend.inspect(before['id'])['running']:
                    raise DrainError('Docker did not stop the drained scheduler.')
                stopped = True
                return True
            if clock() >= deadline:
                raise DrainError('Drain timed out; existing jobs were not killed and scheduler will resume.')
            sleep(1)
    finally:
        try:
            if paused_by_us and not stopped:
                # Recovery must not depend on the Docker API being reachable.
                # This pidfd can only resume the original kernel process; ESRCH
                # after its exit is harmless and never targets a replacement PID.
                backend.signal(before['id'], 'CONT')
        finally:
            closer = getattr(backend, 'close', None)
            if closer is not None:
                closer()


def maintenance_lock():
    import fcntl
    path = '/run/lock/mailhub-maintenance.lock'
    # Shell callers already own fd 9. Reuse that exact open-file description so
    # a nested backup/update call does not deadlock against its parent.
    try:
        inherited, named = os.fstat(9), os.stat(path, follow_symlinks=False)
        reuse = (inherited.st_dev, inherited.st_ino) == (named.st_dev, named.st_ino)
    except OSError:
        reuse = False
    fd = 9 if reuse else os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o022:
        raise DrainError('Unsafe maintenance lock file.')
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise DrainError('Another Mailhub maintenance operation is running.') from None
    return fd


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--timeout', type=int, default=900, help='Maximum drain wait in seconds (1..3600).')
    args = parser.parse_args()
    if sys.platform != 'linux' or os.geteuid() != 0:
        parser.error('Run as root on the Docker Linux host.')
    if not 1 <= args.timeout <= 3600:
        parser.error('timeout must be between 1 and 3600 seconds.')
    def interrupted(signum, frame):
        raise DrainError('Drain interrupted; restoring the original scheduler when possible.')
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        lock_fd = maintenance_lock()
        changed = drain(Docker(), args.timeout)
        print('Mailhub cron drained and stopped.' if changed else 'Mailhub cron is already stopped.', file=sys.stderr)
        return 0
    except DrainError as exc:
        print(f'ERROR: {exc}', file=sys.stderr)
        return 1
    except OSError:
        print('ERROR: Cannot obtain a safe maintenance lock; no maintenance was performed.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
