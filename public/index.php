<?php

declare(strict_types=1);

// load our codebase + composer packages
require __DIR__ . '/../vendor/autoload.php';

// read the env
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// set up logging
$logger = new \Monolog\Logger('cyberpunk');
$logger->pushHandler(
    new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Debug)
);
$logger->info('Application started.');

echo 'cyberpunk: online';
