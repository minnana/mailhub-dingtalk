<?php
declare(strict_types=1);
// Run as container root. Read Docker secrets, then drop privileges before invoking
// the Nextcloud console. Credentials never enter the OS process argument list.
if (PHP_SAPI !== 'cli' || posix_getuid() !== 0) {
    fwrite(STDERR, "Installer requires container root.\n");
    exit(1);
}
ini_set('zend.exception_ignore_args', '1');
chdir('/var/www/html');
$initializationLock = fopen('nextcloud-init-sync.lock', 'c');
if (!$initializationLock || !flock($initializationLock, LOCK_EX)) {
    throw new RuntimeException('Could not acquire initialization lock');
}
if (!is_file('version.php') || !is_file('console.php')) {
    throw new RuntimeException('Nextcloud image initialization is incomplete');
}
if (is_file('config/config.php')) {
    require 'config/config.php';
    if (!empty($CONFIG['installed'])) {
        fwrite(STDOUT, "Nextcloud is already installed; credentials unchanged.\n");
        exit(0);
    }
}
$admin = getenv('MAILHUB_ADMIN_USER');
if (!$admin || !preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $admin)) {
    throw new RuntimeException('Invalid administrator name');
}
$adminPassword = trim((string)file_get_contents('/run/secrets/admin_password'));
$dbPassword = trim((string)file_get_contents('/run/secrets/db_password'));
if (strlen($adminPassword) < 32 || strlen($dbPassword) < 32) {
    throw new RuntimeException('Missing or invalid generated secret');
}
if (!posix_setgid(33) || !posix_setuid(33)) {
    throw new RuntimeException('Could not drop privileges');
}
$_SERVER['argv'] = ['occ', 'maintenance:install', '--no-interaction',
    '--database=mysql', '--database-host=db', '--database-name=nextcloud',
    '--database-user=nextcloud', '--database-pass=' . $dbPassword,
    '--admin-user=' . $admin, '--admin-pass=' . $adminPassword,
    '--data-dir=/var/www/html/data'];
$_SERVER['argc'] = count($_SERVER['argv']);
require '/var/www/html/console.php';
