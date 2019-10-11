<?php

$config = [
	'sid_list' => [
		'hello01' => 'd23fca8ce340f929ebb32c4da9dc9e6c',
    ],
    'db' => [
        'dsn'      => 'mysql:dbname=test_sync1;host=127.0.0.1;port=3306;charset=utf8',
        'user'     => 'root',
        'password' => '',
    ],
];

require 'sync.php';

Sync::server($config);
exit;