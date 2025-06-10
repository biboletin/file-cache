<?php

namespace Biboletin\FileCache;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use DateInterval;
use DateTime;
use Throwable;

/**
 * Class FileCache
 *
 * A simple file-based cache implementation that adheres to PSR-16 (Simple Cache).
 * It stores cache items in files, using a hashed key for file names.
 */
class FileCache implements CacheInterface
{
    /**
     * The directory where cache files are stored.
     *
     * @var string
     */
    protected string $cacheDir;

    /**
     * The default time-to-live (TTL) for cache items in seconds.
     *
     * @var int
     */
    protected int $defaultTtl;

    /**
     * FileCache constructor.
     *
     * @param string $cacheDir   The directory where cache files will be stored.
     * @param int    $defaultTtl The default time-to-live for cache items in seconds.
     *
     * @throws InvalidArgumentException If the cache directory is not writable.
     */
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

    /**
     * Generates a file path for the given cache key.
     *
     * @param string $key The cache key.
     *
     * @return string The file path for the cache item.
     * @throws InvalidArgumentException If the key is invalid.
     */
    public function getFilePath(string $key): string
    {
        $this->validateKey($key);
        $hashedKey = hash('sha256', $key);

        return $this->cacheDir . DIRECTORY_SEPARATOR . $hashedKey . '.cache';
    }

    /**
     * Validates the cache key.
     *
     * @param string $key The cache key to validate.
     *
     * @throws InvalidArgumentException If the key is invalid.
     */
    protected function validateKey(string $key): void
    {
        if (!is_string($key) || preg_match('/[{}()\/\\@:]/', $key)) {
            throw new InvalidArgumentException('Invalid cache key: ' . $key);
        }
    }

    /**
     * Calculates the expiration timestamp based on the TTL.
     *
     * @param DateInterval|int|null $ttl The time-to-live (TTL) for the cache item.
     *
     * @return int The expiration timestamp.
     */
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

    /**
     * Retrieves the cache item for the given key.
     *
     * @param string     $key     The cache key.
     * @param mixed|null $default The default value to return if the cache item does not exist.
     *
     * @return mixed The cached value or the default value.
     */
    public function get(string $key, mixed $default = null): mixed
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

    /**
     * Sets a cache item for the given key with an optional TTL.
     *
     * @param string                $key   The cache key.
     * @param mixed                 $value The value to cache.
     * @param DateInterval|int|null $ttl   The time-to-live (TTL) for the cache item.
     *
     * @return bool True on success, false on failure.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
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

    /**
     * Deletes a cache item for the given key.
     *
     * @param string $key The cache key.
     *
     * @return bool True on success, false on failure.
     */
    public function delete(string $key): bool
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

    /**
     * Clears all cache items.
     *
     * @return bool True on success, false on failure.
     */
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

    /**
     * Retrieves multiple cache items for the given keys.
     *
     * @param iterable<string, mixed> $keys    The cache keys.
     * @param mixed|null              $default The default value to return if a cache item does not exist.
     *
     * @return iterable An associative array of key-value pairs.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * Sets multiple cache items.
     *
     * @param iterable              $values An associative array of key-value pairs to cache.
     * @param DateInterval|int|null $ttl    The time-to-live (TTL) for the cache items.
     *
     * @return bool True on success, false on failure.
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
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

    /**
     * Deletes multiple cache items for the given keys.
     *
     * @param iterable $keys The cache keys to delete.
     *
     * @return bool True on success, false on failure.
     */
    public function deleteMultiple(iterable $keys): bool
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

    /**
     * Checks if a cache item exists for the given key.
     *
     * @param string $key The cache key.
     *
     * @return bool True if the cache item exists and is not expired, false otherwise.
     */
    public function has(string $key): bool
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
