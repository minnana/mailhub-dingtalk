<?php
declare(strict_types=1);
return ['routes' => [
    ['name' => 'login#index', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'login#begin', 'url' => '/begin', 'verb' => 'POST'],
    ['name' => 'login#authenticate', 'url' => '/auth', 'verb' => 'POST'],
    ['name' => 'personal#index', 'url' => '/personal', 'verb' => 'GET'],
    ['name' => 'personal#password', 'url' => '/personal/password', 'verb' => 'POST'],
    ['name' => 'admin#index', 'url' => '/admin', 'verb' => 'GET'],
    ['name' => 'admin#status', 'url' => '/admin/status', 'verb' => 'GET'],
    ['name' => 'admin#preferences', 'url' => '/admin/preferences', 'verb' => 'POST'],
    ['name' => 'admin#userState', 'url' => '/admin/users/state', 'verb' => 'POST'],
]];
