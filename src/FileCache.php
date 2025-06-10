<?php

namespace Biboletin\FileCache;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use DateInterval;
use DateTime;
use Throwable;

class FileCache implements CacheInterface
{
    protected string $cacheDir;
    protected int $defaultTtl;

    public function __construct(string $cacheDir, int $defaultTtl = 3600)
    {
        $this->cacheDir = rtrim($cacheDir, '/') . '/';
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        if (!is_writable($cacheDir)) {
            throw new InvalidArgumentException('Cache directory must be a writable directory.');
        }
    }

    public function getFilePath(string $key): string
    {
        $this->validateKey($key);
        $hashedKey = hash('sha256', $key);

        return $this->cacheDir . DIRECTORY_SEPARATOR . $hashedKey . '.cache';
    }

    protected function validateKey(string $key): void
    {
        if (!is_string($key) || preg_match('/[{}()\/\\@:]/', $key)) {
            throw new InvalidArgumentException('Invalid cache key: ' . $key);
        }
    }

    public function getExpirationTimestamp($ttl): int
    {
        if ($ttl instanceof DateInterval) {
            $now = new DateTime();
            return $now->add($ttl)->getTimestamp();
        }

        if (is_int($ttl)) {
            return time() + $ttl;
        }

        return time() + $this->defaultTtl;
    }

    // Implement the methods required by the CacheInterface here
    public function get($key, $default = null): mixed
    {
        try {
            $this->validateKey($key);
            $file = $this->getFilePath($key);

            if (!file_exists($file)) {
                return $default;
            }

            $data = unserialize(file_get_contents($file) ?: '');

            if ($data === false || !is_array($data) || !isset($data['value'], $data['expiration'])) {
                return $default;
            }

            if (time() > $data['expiration']) {
                // Remove an expired cache file
                unlink($file);
                return $default;
            }

            return $data['value'];
        } catch (Throwable $e) {
            return $default;
        }
    }

    public function set($key, $value, $ttl = null): bool
    {
        try {
            $this->validateKey($key);
            $file = $this->getFilePath($key);
            $expiration = $this->getExpirationTimestamp($ttl);

            $data = [
                'value' => $value,
                'expiration' => $expiration,
            ];

            $encodedData = serialize($data);

            return file_put_contents($file, $encodedData) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function delete($key): bool
    {
        try {
            $this->validateKey($key);
            $file = $this->getFilePath($key);

            if (file_exists($file)) {
                return unlink($file);
            }

            // If the file does not exist, consider it deleted
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $files = glob($this->cacheDir . '*.cache') ?: [];

            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        if (!is_iterable($values)) {
            throw new InvalidArgumentException('Values must be iterable.');
        }

        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple($keys): bool
    {
        if (!is_iterable($keys)) {
            throw new InvalidArgumentException('Keys must be iterable.');
        }

        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function has($key): bool
    {
        try {
            $this->validateKey($key);
            $file = $this->getFilePath($key);

            if (!file_exists($file)) {
                return false;
            }

            $data = unserialize(file_get_contents($file) ?: '');

            if ($data === false || !is_array($data) || !isset($data['expiration'])) {
                return false;
            }

            return time() <= $data['expiration'];
        } catch (Throwable $e) {
            return false;
        }
    }
}
