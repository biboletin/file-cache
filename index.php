<?php


use Biboletin\FileCache\FileCache;

include __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(__DIR__ . '/var/cache', 'my_secret', 3600);
$cache->set('test_key', 'test_value', 3600);

$value = $cache->get('test_key');
echo "Cached value: " . $value . PHP_EOL;

$cache->purge();
