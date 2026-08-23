<?php

require __DIR__.'/../vendor/autoload.php';

$connection = getenv('DB_CONNECTION') ?: 'sqlite';

if ($connection !== 'sqlite') {
    return;
}

$database = getenv('DB_DATABASE') ?: 'test_db.sqlite';

if ($database === '' || $database === ':memory:') {
    return;
}

$path = str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $database) === 1
    ? $database
    : __DIR__.'/../database/'.$database;

if (file_exists($path)) {
    unlink($path);
}

$directory = dirname($path);

if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}

touch($path);
