<?php

$config = [
	'server_url' => 'http://www.z1.com/db_sync/server.php',
	'sid' => 'hello01',
	'key' => 'd23fca8ce340f929ebb32c4da9dc9e6c',
    'db' => [
        'dsn'      => 'mysql:dbname=test_sync2;host=127.0.0.1;port=3306;charset=utf8',
        'user'     => 'root',
        'password' => '',
    ],
	'table_list' => [
		['table' => 'user'                , 'type' => 'full',],
        ['table' => 'user_type'           , 'type' => 'full',],
		['table' => 'sys_log'             , 'type' => 'id',],
		['table' => 'device_log'          , 'type' => 'id',],
		['table' => 'device'              , 'type' => 'sync_id',],
        ['table' => 'client'              , 'type' => 'sync_id',],
    ],
];

require 'sync.php';

Sync::client($config);
exit;