<?php


use Biboletin\FileCache\FileCache;

include __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(__DIR__ . '/var/cache');
try {
    $cache->set('test_key', 'test_value', 3600);
} catch (\Psr\SimpleCache\InvalidArgumentException $e) {
    
}

try {
    $value = $cache->get('test_key');
    echo "Cached value: " . $value . PHP_EOL;
} catch (\Psr\SimpleCache\InvalidArgumentException $e) {
    echo "Error retrieving cached value: " . $e->getMessage() . PHP_EOL;
}
