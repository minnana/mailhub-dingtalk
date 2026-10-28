#!/usr/bin/env python3
"""Offline patch safety tests. Provide the verified release tarball; never use a live Mail directory."""
import argparse
import importlib.util
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest import mock

SPEC = importlib.util.spec_from_file_location("mail_sync_token_patch", Path(__file__).resolve().parents[1] / "templates/mail_sync_token_patch.py")
patch = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(patch)
ORIGINAL = b""


class PatchTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="mailhub-patch-test-")
        self.base = Path(self.temp.name).resolve()
        self.mail = self.base / "mail"
        self.target = self.mail / patch.RELATIVE_FILE
        self.target.parent.mkdir(parents=True)
        (self.mail / "appinfo").mkdir()
        (self.mail / "appinfo/info.xml").write_text("<info><version>5.12.5</version></info>")
        self.target.write_bytes(ORIGINAL)
        self.backup = self.base / "rollback"

    def tearDown(self):
        self.temp.cleanup()

    def test_reviewed_hashes(self):
        self.assertEqual(patch.digest(ORIGINAL), patch.ORIGINAL_SHA256)
        self.assertEqual(patch.digest(patch.apply_bytes(ORIGINAL)), patch.PATCHED_SHA256)

    def test_minimal_empty_token_readonly_fallback(self):
        changed = patch.apply_bytes(ORIGINAL)
        reverted = changed.replace(patch.REPLACEMENT, patch.ANCHOR, 1).replace(patch.PARTIAL_REPLACEMENT, patch.PARTIAL_ANCHOR, 1)
        self.assertEqual(reverted, ORIGINAL)
        self.assertIn(b"if ($syncToken === '')", patch.REPLACEMENT)
        self.assertIn(b"Horde_Imap_Client::OPEN_READONLY", patch.REPLACEMENT)
        self.assertIn(b"throw new ServiceException", patch.REPLACEMENT)
        self.assertNotIn(b"resetCache", patch.REPLACEMENT)
        self.assertNotIn(b"flag", patch.REPLACEMENT.split(b"if ($syncToken")[1])

    def test_partial_guard_is_readonly_and_does_not_replace_tokens(self):
        self.assertIn(b"getSyncToken($mailbox->getName()) === ''", patch.PARTIAL_GUARD)
        self.assertIn(b"Horde_Imap_Client::OPEN_READONLY", patch.PARTIAL_GUARD)
        self.assertIn(b"throw new ServiceException", patch.PARTIAL_GUARD)
        for forbidden in (b"resetCache", b"setSync", b"catch", b"return"):
            self.assertNotIn(forbidden, patch.PARTIAL_GUARD)

    def test_v1_requires_restore_before_v2(self):
        first_patch = ORIGINAL.replace(patch.ANCHOR, patch.REPLACEMENT, 1)
        self.assertEqual(patch.digest(first_patch), "94df1449b7745d91ff2ae3602e2fceb21d04b5a8c4e7cd3efc0968800e322ce9")
        self.target.write_bytes(first_patch)
        with self.assertRaises(patch.PatchError): patch.manage("apply", self.mail, self.backup)
        self.assertEqual(self.target.read_bytes(), first_patch)

    def test_directory_sync_failure_does_not_patch_source(self):
        self.backup.mkdir(mode=0o700)
        real_sync = patch._fsync_directory
        def fail_after_backup(directory):
            if (directory / "manifest.json").exists():
                raise OSError("simulated directory durability failure")
            return real_sync(directory)
        with mock.patch.object(patch, "_fsync_directory", side_effect=fail_after_backup):
            with self.assertRaises(OSError): patch.manage("apply", self.mail, self.backup)
        self.assertEqual(self.target.read_bytes(), ORIGINAL)

    def test_backup_directory_synced_before_replacement(self):
        events = []
        real_sync, real_replace = patch._fsync_directory, patch._atomic_replace
        def sync(directory):
            events.append(("sync", (directory / "manifest.json").exists()))
            return real_sync(directory)
        def replace(*args):
            self.assertIn(("sync", True), events)
            events.append(("replace", True))
            return real_replace(*args)
        with mock.patch.object(patch, "_fsync_directory", side_effect=sync), mock.patch.object(patch, "_atomic_replace", side_effect=replace):
            patch.manage("apply", self.mail, self.backup)
        self.assertEqual(events[-1], ("replace", True))

    def test_apply_preserves_original(self):
        result = patch.manage("apply", self.mail, self.backup)
        self.assertTrue(result["changed"])
        self.assertEqual(self.target.read_bytes(), patch.apply_bytes(ORIGINAL))
        self.assertEqual((self.backup / "ImapToDbSynchronizer.php.original").read_bytes(), ORIGINAL)

    def test_idempotent_apply(self):
        patch.manage("apply", self.mail, self.backup)
        self.assertFalse(patch.manage("apply", self.mail, self.backup)["changed"])

    def test_restore_and_idempotence(self):
        patch.manage("apply", self.mail, self.backup)
        self.assertTrue(patch.manage("restore", self.mail, self.backup)["changed"])
        self.assertEqual(self.target.read_bytes(), ORIGINAL)
        self.assertFalse(patch.manage("restore", self.mail, self.backup)["changed"])

    def test_reapply_after_restore(self):
        patch.manage("apply", self.mail, self.backup)
        patch.manage("restore", self.mail, self.backup)
        self.assertTrue(patch.manage("apply", self.mail, self.backup)["changed"])

    def test_status_does_not_create_backup(self):
        self.assertEqual(patch.manage("status", self.mail, self.backup)["state"], "original")
        self.assertFalse(self.backup.exists())

    def test_unreviewed_version_refused(self):
        (self.mail / "appinfo/info.xml").write_text("<info><version>5.12.6</version></info>")
        with self.assertRaises(patch.PatchError): patch.manage("apply", self.mail, self.backup)
        self.assertEqual(self.target.read_bytes(), ORIGINAL)

    def test_source_drift_refused(self):
        content = ORIGINAL + b"\n// unrelated modification\n"
        self.target.write_bytes(content)
        with self.assertRaises(patch.PatchError): patch.manage("apply", self.mail, self.backup)
        self.assertEqual(self.target.read_bytes(), content)

    def test_restore_drift_refused(self):
        patch.manage("apply", self.mail, self.backup)
        content = self.target.read_bytes() + b"\n// new change\n"
        self.target.write_bytes(content)
        with self.assertRaises(patch.PatchError): patch.manage("restore", self.mail, self.backup)
        self.assertEqual(self.target.read_bytes(), content)

    def test_corrupt_backup_refused(self):
        patch.manage("apply", self.mail, self.backup)
        (self.backup / "ImapToDbSynchronizer.php.original").write_bytes(b"bad")
        with self.assertRaises(patch.PatchError): patch.manage("restore", self.mail, self.backup)
        self.assertEqual(patch.digest(self.target.read_bytes()), patch.PATCHED_SHA256)

    def test_non_object_manifest_refused(self):
        patch.manage("apply", self.mail, self.backup)
        (self.backup / "manifest.json").write_text("[]", encoding="utf-8")
        with self.assertRaises(patch.PatchError): patch.manage("restore", self.mail, self.backup)
        self.assertEqual(patch.digest(self.target.read_bytes()), patch.PATCHED_SHA256)

    def test_missing_backup_of_patched_file_refused(self):
        self.target.write_bytes(patch.apply_bytes(ORIGINAL))
        with self.assertRaises(FileNotFoundError): patch.manage("apply", self.mail, self.backup)
        self.assertEqual(patch.digest(self.target.read_bytes()), patch.PATCHED_SHA256)

    def test_backup_cannot_be_inside_app(self):
        with self.assertRaises(patch.PatchError): patch.manage("apply", self.mail, self.mail / "backup")

    def test_relative_path_refused(self):
        with self.assertRaises(patch.PatchError): patch.manage("apply", Path("mail"), self.backup)

    def test_hardlinked_source_refused(self):
        os.link(self.target, self.base / "second-source-link")
        with self.assertRaises(patch.PatchError): patch.manage("apply", self.mail, self.backup)

    def test_symlink_source_refused(self):
        saved = self.base / "saved"
        self.target.rename(saved)
        try: self.target.symlink_to(saved)
        except OSError: self.skipTest("Host cannot create symlinks")
        with self.assertRaises(patch.PatchError): patch.manage("apply", self.mail, self.backup)

    def test_original_metadata_preserved(self):
        if os.name != "posix": self.skipTest("Unix ownership/mode test runs in Linux")
        self.target.chmod(0o640)
        before = self.target.stat()
        patch.manage("apply", self.mail, self.backup)
        patch.manage("restore", self.mail, self.backup)
        after = self.target.stat()
        self.assertEqual((before.st_uid, before.st_gid, before.st_mode & 0o777), (after.st_uid, after.st_gid, after.st_mode & 0o777))
        self.assertEqual((self.backup / "manifest.json").stat().st_mode & 0o777, 0o600)

    def test_patch_operation_does_not_touch_other_files(self):
        other = self.mail / "keep.txt"; other.write_bytes(b"untouched")
        patch.manage("apply", self.mail, self.backup)
        self.assertEqual(other.read_bytes(), b"untouched")


def php_checks():
    """Emit an isolated PHP fixture: only fake clients, no Nextcloud bootstrap/network/data."""
    initial, partial = patch.REPLACEMENT.decode(), patch.PARTIAL_GUARD.decode()
    return r'''<?php
class ServiceException extends RuntimeException {}
class Horde_Imap_Client { const OPEN_READONLY = 1; }
class FakeMailbox { public function getName() { return 'Sent'; } }
class FakeClient {
    public array $calls = [];
    public bool $continued = false;
    public function __construct(private array $tokens, private bool $selectionFails = false) {}
    public function getSyncToken($name) { $this->calls[] = ['token', $name]; return array_shift($this->tokens); }
    public function openMailbox($name, $mode) {
        $this->calls[] = ['open', $name, $mode];
        if ($this->selectionFails) { throw new RuntimeException('simulated unavailable folder'); }
    }
}
function initial($client, $mailbox) {
''' + initial + r'''
    $client->continued = true; return $syncToken;
}
function partial($client, $mailbox) {
''' + partial + r'''
    $client->continued = true;
}
$checks = 0;
function expect($condition) { global $checks; if (!$condition) { throw new RuntimeException('guard check failed'); } ++$checks; }
foreach (['initial', 'partial'] as $method) {
    $mailbox = new FakeMailbox();
    $client = new FakeClient(['V123']); $method($client, $mailbox);
    expect($client->continued && $client->calls === [['token', 'Sent']]);
    $client = new FakeClient(['', 'V123']); $method($client, $mailbox);
    expect($client->continued && $client->calls === [['token', 'Sent'], ['open', 'Sent', 1], ['token', 'Sent']]);
    $client = new FakeClient(['', '']); $failed = false;
    try { $method($client, $mailbox); } catch (ServiceException) { $failed = true; }
    expect($failed && !$client->continued && count($client->calls) === 3);
    $client = new FakeClient([''], true); $failed = false;
    try { $method($client, $mailbox); } catch (RuntimeException) { $failed = true; }
    expect($failed && !$client->continued && count($client->calls) === 2);
}
echo "Mail sync-token guard checks: $checks passed\n";
'''


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mail-archive", required=True, type=Path)
    parser.add_argument("--emit-php-checks", action="store_true", help="Emit isolated behavioral tests to run with PHP over stdin.")
    args = parser.parse_args()
    with tarfile.open(args.mail_archive, "r:gz") as archive:
        ORIGINAL = archive.extractfile("mail/lib/Service/Sync/ImapToDbSynchronizer.php").read()
    if patch.digest(ORIGINAL) != patch.ORIGINAL_SHA256:
        parser.error("Release archive does not contain the reviewed original source")
    if args.emit_php_checks:
        print(php_checks())
    else:
        unittest.main(argv=[__file__])
