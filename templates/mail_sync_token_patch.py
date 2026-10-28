#!/usr/bin/env python3
"""Controlled, reversible Mail 5.12.5 empty sync-token compatibility patch.

This modifies ONE official Mail source file and therefore changes that app's
integrity hash. It never disables Nextcloud core or app integrity checking.
Apply/restore only with the application and cron drained by the deployment scripts.
No database, cache, message flag, message content or mailbox credential is touched.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import tempfile
import xml.etree.ElementTree as ET

VERSION = "5.12.5"
RELATIVE_FILE = Path("lib/Service/Sync/ImapToDbSynchronizer.php")
ORIGINAL_SHA256 = "40f47142dc1dceee498a8d9dcb3f3d9c33ae52e8889056d836fb9d45d03c3e10"
PATCHED_SHA256 = "4ed4a2fb896e06131e3577c1b225d592da042af8106711ae9b4e7ea81f43d20c"
ANCHOR = b"\t\t$syncToken = $client->getSyncToken($mailbox->getName());\n"
REPLACEMENT = ANCHOR + b"""\t\t// Mailhub compatibility: some IMAP servers omit STATUS data for an unselected folder.
\t\t// EXAMINE selects it read-only and leaves message flags unchanged.
\t\tif ($syncToken === '') {
\t\t\t$client->openMailbox($mailbox->getName(), \\Horde_Imap_Client::OPEN_READONLY);
\t\t\t$syncToken = $client->getSyncToken($mailbox->getName());
\t\t\tif ($syncToken === '') {
\t\t\t\tthrow new ServiceException('Mailbox sync token unavailable after read-only selection');
\t\t\t}
\t\t}
"""
PARTIAL_ANCHOR = b"\t\t?array $knownUids = null): bool {\n\t\t$newOrVanished = false;\n"
PARTIAL_GUARD = b"""\t\t// Missing STATUS metadata is not proof that UIDVALIDITY changed.
\t\t// Select read-only only in that case, then retain the native token comparison.
\t\tif ($client->getSyncToken($mailbox->getName()) === '') {
\t\t\t$client->openMailbox($mailbox->getName(), \\Horde_Imap_Client::OPEN_READONLY);
\t\t\tif ($client->getSyncToken($mailbox->getName()) === '') {
\t\t\t\tthrow new ServiceException('Mailbox sync token unavailable after read-only selection');
\t\t\t}
\t\t}
"""
PARTIAL_REPLACEMENT = PARTIAL_ANCHOR.replace(b"\t\t$newOrVanished = false;\n", PARTIAL_GUARD + b"\t\t$newOrVanished = false;\n")


class PatchError(RuntimeError):
    pass


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def apply_bytes(original: bytes) -> bytes:
    if digest(original) != ORIGINAL_SHA256 or original.count(ANCHOR) != 1 or original.count(PARTIAL_ANCHOR) != 1:
        raise PatchError("Official Mail source does not match the reviewed 5.12.5 SHA256")
    patched = original.replace(ANCHOR, REPLACEMENT, 1).replace(PARTIAL_ANCHOR, PARTIAL_REPLACEMENT, 1)
    if digest(patched) != PATCHED_SHA256:
        raise PatchError("Generated patch does not match the reviewed output SHA256")
    return patched


def _absolute_no_links(path: Path) -> Path:
    path = Path(path)
    if not path.is_absolute() or path.absolute() != path.resolve():
        raise PatchError("Paths must be absolute, normalized and contain no symlinks")
    return path


def _read_regular(path: Path, limit: int = 1024 * 1024) -> tuple[bytes, os.stat_result]:
    _absolute_no_links(path)
    st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or st.st_size > limit:
        raise PatchError("Expected one bounded, regular, unlinked source/backup file")
    with path.open("rb") as stream:
        opened = os.fstat(stream.fileno())
        if (opened.st_dev, opened.st_ino) != (st.st_dev, st.st_ino):
            raise PatchError("File changed while it was being opened")
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise PatchError("File exceeds its size limit")
    return data, st


def inspect(mail_root: Path) -> tuple[Path, bytes, os.stat_result, str]:
    mail_root = _absolute_no_links(mail_root)
    version_xml, _ = _read_regular(mail_root / "appinfo/info.xml", 128 * 1024)
    if (ET.fromstring(version_xml).findtext("version") or "").strip() != VERSION:
        raise PatchError("Only the reviewed Mail 5.12.5 release is supported")
    target = mail_root / RELATIVE_FILE
    content, metadata = _read_regular(target)
    current = digest(content)
    state = {ORIGINAL_SHA256: "original", PATCHED_SHA256: "patched"}.get(current)
    if state is None:
        raise PatchError("Source drift detected; refusing to overwrite an unreviewed file")
    return target, content, metadata, state


def _secure_backup_directory(directory: Path, mail_root: Path) -> Path:
    directory = _absolute_no_links(directory)
    if directory == mail_root or directory.is_relative_to(mail_root):
        raise PatchError("Rollback copies must be outside the installed Mail app")
    missing = []
    parent = directory
    while not parent.exists():
        missing.append(parent)
        parent = parent.parent
    mask = os.umask(0o077)
    try:
        directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    finally:
        os.umask(mask)
    st = directory.lstat()
    if not stat.S_ISDIR(st.st_mode):
        raise PatchError("Rollback directory is not a directory")
    if os.name == "posix" and (st.st_uid != os.geteuid() or stat.S_IMODE(st.st_mode) != 0o700):
        raise PatchError("Rollback directory must be owned by the operator with mode 0700")
    # Persist newly created directory entries before any installed source change.
    for created in reversed(missing):
        _fsync_directory(created.parent)
    _fsync_directory(directory)
    return directory


def _fsync_directory(directory: Path) -> None:
    if os.name == "posix":
        fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)


def _write_exclusive(path: Path, content: bytes) -> None:
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(path, flags, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())


def _backup_paths(directory: Path) -> tuple[Path, Path]:
    return directory / "ImapToDbSynchronizer.php.original", directory / "manifest.json"


def _verify_backup(directory: Path, target: Path) -> tuple[bytes, dict]:
    backup, manifest = _backup_paths(directory)
    original, _ = _read_regular(backup)
    metadata_bytes, _ = _read_regular(manifest, 16384)
    metadata = json.loads(metadata_bytes)
    if not isinstance(metadata, dict):
        raise PatchError("Rollback manifest must be a metadata object")
    if digest(original) != ORIGINAL_SHA256 or metadata.get("version") != VERSION \
            or metadata.get("target") != str(target) or metadata.get("original_sha256") != ORIGINAL_SHA256 \
            or metadata.get("patched_sha256") != PATCHED_SHA256:
        raise PatchError("Rollback copy or manifest does not match this reviewed target")
    for key in ("uid", "gid", "mode"):
        if type(metadata.get(key)) is not int or metadata[key] < 0:
            raise PatchError("Invalid original file metadata")
    if metadata["mode"] > 0o777:
        raise PatchError("Unexpected special mode bits in rollback metadata")
    return original, metadata


def _preserve_original(directory: Path, target: Path, content: bytes, st: os.stat_result) -> None:
    backup, manifest = _backup_paths(directory)
    if backup.exists() or backup.is_symlink():
        saved, _ = _read_regular(backup)
        if digest(saved) != ORIGINAL_SHA256:
            raise PatchError("Existing rollback copy is not the reviewed original")
    else:
        _write_exclusive(backup, content)
    if not manifest.exists() and not manifest.is_symlink():
        metadata = {"version": VERSION, "target": str(target), "original_sha256": ORIGINAL_SHA256,
                    "patched_sha256": PATCHED_SHA256, "uid": st.st_uid, "gid": st.st_gid,
                    "mode": stat.S_IMODE(st.st_mode)}
        _write_exclusive(manifest, (json.dumps(metadata, sort_keys=True) + "\n").encode())
    _verify_backup(directory, target)
    _fsync_directory(directory)


def _atomic_replace(target: Path, expected: bytes, content: bytes, metadata: dict) -> None:
    fd, temp_name = tempfile.mkstemp(prefix=".mailhub-sync-patch-", dir=target.parent)
    temp_path = Path(temp_name)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(content)
            stream.flush()
            if os.name == "posix":
                os.fchown(stream.fileno(), metadata["uid"], metadata["gid"])
                os.fchmod(stream.fileno(), metadata["mode"])
            os.fsync(stream.fileno())
        current, _ = _read_regular(target)
        if current != expected:
            raise PatchError("Source changed during the operation; no replacement performed")
        os.replace(temp_path, target)
        if os.name == "posix":
            directory_fd = os.open(target.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory_fd)
            finally:
                os.close(directory_fd)
    finally:
        if temp_path.exists():
            temp_path.unlink()


def manage(action: str, mail_root: Path, backup_dir: Path) -> dict:
    if action not in ("apply", "restore", "status"):
        raise PatchError("Unknown action")
    target, current, st, state = inspect(mail_root)
    result = {"version": VERSION, "state": state, "changed": False,
              "original_sha256": ORIGINAL_SHA256, "patched_sha256": PATCHED_SHA256,
              "official_integrity_hash_changed": state == "patched"}
    if action == "status" or (action == "restore" and state == "original"):
        return result
    backup_dir = _secure_backup_directory(backup_dir, Path(mail_root))
    if action == "apply" and state == "original":
        _preserve_original(backup_dir, target, current, st)
    original, metadata = _verify_backup(backup_dir, target)
    if action == "apply" and state == "patched":
        return result
    replacement = apply_bytes(original) if action == "apply" else original
    _atomic_replace(target, current, replacement, metadata)
    result.update(state="patched" if action == "apply" else "original", changed=True,
                  official_integrity_hash_changed=action == "apply")
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("apply", "restore", "status"))
    parser.add_argument("--mail-root", type=Path, default=Path("/opt/mailhub/runtime/data/nextcloud/custom_apps/mail"))
    parser.add_argument("--backup-dir", type=Path, default=Path("/opt/mailhub/runtime/patch-backups/mail-sync-token-5.12.5-v2"))
    args = parser.parse_args()
    if os.name != "posix" or os.geteuid() != 0:
        parser.error("Run the patch helper as root on the Linux deployment host")
    try:
        if args.action == "status":
            result = manage(args.action, args.mail_root, args.backup_dir)
        else:
            # Separate from the deployment's own maintenance lock, so a drained
            # start/update caller can invoke this helper without recursive deadlock.
            import fcntl
            backup_dir = _secure_backup_directory(args.backup_dir, _absolute_no_links(args.mail_root))
            lock_path = backup_dir / ".operation.lock"
            flags = os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
            fd = os.open(lock_path, flags, 0o600)
            with os.fdopen(fd, "rb") as lock:
                st = os.fstat(lock.fileno())
                if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or st.st_uid != 0 or stat.S_IMODE(st.st_mode) != 0o600:
                    raise PatchError("Unsafe patch operation lock")
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                result = manage(args.action, args.mail_root, args.backup_dir)
        print(json.dumps(result, sort_keys=True))
        return 0
    except (PatchError, OSError, ValueError, ET.ParseError):
        print("Mail sync-token patch refused or failed; verify the pinned version, source hash and protected rollback copy.", file=__import__("sys").stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
