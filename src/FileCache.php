<?php

namespace Biboletin\FileCache;

use http\Exception\RuntimeException;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use DateInterval;
use DateTime;
use Random\RandomException;
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
    protected int $defaultTtl = 3600;
    
    /**
     * The time-to-live (TTL) for cache items in seconds.
     *
     * @var int
     */
    protected int $ttl = 3600;

    /**
     * The cipher method used for encryption.
     *
     * @var string
     */
    protected string $cipher = 'aes-256-cbc';

    /**
     * The encryption key used for encrypting cache items.
     *
     * @var string
     */
    protected string $encryptionKey = '';

    /**
     * FileCache constructor.
     *
     * @param string   $cacheDir The directory where cache files will be stored.
     * @param string   $encryptionKey
     * @param int|null $ttl
     */
    public function __construct(string $cacheDir, string $encryptionKey, ?int $ttl = null)
    {
        $this->cacheDir = rtrim($cacheDir, '/') . '/';
        $this->ttl = ($ttl !== null) ? $ttl : $this->defaultTtl;
        $this->encryptionKey = hash('sha256', $encryptionKey, true);

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

        return $this->getCacheDirectory() . DIRECTORY_SEPARATOR . $hashedKey . '.cache';
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
    public function getExpirationTimestamp(DateInterval|int|null $ttl): int
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

            $rawData = file_get_contents($file);
            if ($rawData === false) {
                return $default;
            }

            $serialized = $this->decrypt($rawData);
            $data = unserialize($serialized);

            if (!is_array($data) || !isset($data['value'], $data['expiration'])) {
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

            $encodedData = $this->encrypt(serialize($data));
            chmod($file, 0777);

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
            $files = glob($this->getCacheDirectory() . '*.cache') ?: [];

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
     * @return iterable<string, mixed> An associative array of key-value pairs.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!is_iterable($keys)) {
            throw new InvalidArgumentException('Keys must be iterable.');
        }

        $results = [];

        foreach ($keys as $key) {
            try {
                $this->validateKey($key);
                $file = $this->getFilePath($key);

                if (!file_exists($file)) {
                    $results[$key] = $default;
                    continue;
                }

                $rawData = file_get_contents($file);
                if ($rawData === false) {
                    $results[$key] = $default;
                    continue;
                }

                $serialized = $this->decrypt($rawData);
                $data = unserialize($serialized);

                if (
                    !is_array($data) ||
                    !isset($data['value'], $data['expiration']) ||
                    $data['expiration'] < time()
                ) {
                    $this->delete($key); // Clean up expired
                    $results[$key] = $default;
                    continue;
                }

                $results[$key] = $data['value'];
            } catch (Throwable) {
                $results[$key] = $default;
            }
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
            try {
                $this->validateKey($key);
                if (!$this->delete($key)) {
                    $success = false;
                }
            } catch (Throwable) {
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

    /**
     * Purges expired cache items.
     *
     * This method scans the cache directory and removes files that are expired
     * or corrupted. It returns true if all deletions were successful, false otherwise.
     *
     * @return bool True on success, false on failure.
     */
    public function purge(): bool
    {
        $success = true;

        foreach (glob($this->getCacheDirectory() . '/*') as $file) {
            if (!is_file($file)) {
                continue;
            }

            try {
                $rawData = file_get_contents($file);
                if ($rawData === false) {
                    continue;
                }

                $serialized = $this->decrypt($rawData);
                $data = unserialize($serialized);

                if (
                    !is_array($data) ||
                    !isset($data['expiration']) ||
                    $data['expiration'] < time()
                ) {
                    if (!unlink($file)) {
                        $success = false;
                    }
                }
            } catch (Throwable) {
                // Corrupted or unreadable file — try deleting
                if (!unlink($file)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Encrypts the given data using the configured cipher and encryption key.
     *
     * @param string $data The data to encrypt.
     *
     * @return string The encrypted data, base64-encoded.
     * @throws RuntimeException If encryption fails.
     * @throws RandomException
     */
    protected function encrypt(string $data): string
    {
        if ($this->getEncryptionKey() === '') {
            return $data;
        }

        $ivLength = openssl_cipher_iv_length($this->getCipher());
        if ($ivLength === false) {
            throw new RuntimeException('Invalid cipher method ' . $this->getCipher() . '.');
        }
        $iv = random_bytes($ivLength);
        $encryptedData = openssl_encrypt($data, $this->getCipher(), $this->getEncryptionKey(), 0, $iv);
        if ($encryptedData === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $encryptedData); // Prepend IV for decryption
    }

    /**
     * Decrypts the given data using the configured cipher and encryption key.
     *
     * @param string $data The base64-encoded encrypted data to decrypt.
     *
     * @return string The decrypted data.
     * @throws RuntimeException If decryption fails or if the data is invalid.
     */
    protected function decrypt(string $data): string
    {
        if ($this->encryptionKey === '') {
            return $data;
        }

        $decodedData = base64_decode($data, true);
        if ($decodedData === false) {
            throw new RuntimeException('Decoding failed.');
        }
        $ivLength = openssl_cipher_iv_length($this->getcipher());
        if ($ivLength === false || strlen($decodedData) < $ivLength) {
            throw new RuntimeException('Invalid data length for decryption.');
        }
        $iv = substr($decodedData, 0, $ivLength);
        $encryptedData = substr($decodedData, $ivLength);
        $decryptedData = openssl_decrypt($encryptedData, $this->getCipher(), $this->encryptionKey, 0, $iv);
        if ($decryptedData === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $decryptedData;
    }

    /**
     * Sets the cipher method for encryption.
     * This method allows you to specify the cipher method used for encrypting cache items.
     * It validates the cipher method against the available OpenSSL cipher methods.
     * If the cipher method is invalid, it throws an InvalidArgumentException.
     *
     * @param string $cipher The cipher method to use (e.g., 'aes-256-cbc').
     *
     * @throws InvalidArgumentException If the cipher method is invalid.
     */
    public function setCipher(string $cipher): void
    {
        if (!in_array($cipher, openssl_get_cipher_methods())) {
            throw new InvalidArgumentException('Invalid cipher method: ' . $cipher);
        }
        $this->cipher = $cipher;
    }

    /**
     * Sets the cache directory.
     * This method allows you to specify the directory where cache files will be stored.
     * It validates that the directory exists and is writable.
     *
     * @param string $cacheDir The directory where cache files will be stored.
     *
     * @throws InvalidArgumentException If the directory does not exist or is not writable.
     */
    public function setCacheDirectory(string $cacheDir): void
    {
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            throw new InvalidArgumentException('Cache directory must be a writable directory.');
        }
        $this->cacheDir = rtrim($cacheDir, '/') . '/';
    }

    /**
     * Sets the encryption key.
     * This method allows you to specify the encryption key used for encrypting cache items.
     * The key is hashed using SHA-256 to ensure it is of the correct length for AES-256 encryption.
     *
     * @param string $encryptionKey The encryption key to use.
     */
    public function setEncryptionKey(string $encryptionKey): void
    {
        $this->encryptionKey = hash('sha256', $encryptionKey, true);
    }

    /**
     * Sets the default time-to-live (TTL) for cache items.
     * This method allows you to specify the default TTL for cache items in seconds.
     * If the TTL is negative, it throws an InvalidArgumentException.
     *
     * @param int $ttl The default TTL in seconds.
     *
     * @throws InvalidArgumentException If the TTL is negative.
     */
    public function setTtl(int $ttl): void
    {
        if ($ttl < 0) {
            throw new InvalidArgumentException('TTL must be a non-negative integer.');
        }
        $this->defaultTtl = $ttl;
    }

    /**
     * Gets the cache directory.
     * This method returns the directory where cache files are stored.
     *
     * @return string The cache directory.
     */
    public function getCacheDirectory(): string
    {
        return $this->cacheDir;
    }

    /**
     * Gets the encryption key.
     * This method returns the encryption key used for encrypting cache items.
     *
     * @return string The encryption key.
     */
    public function getEncryptionKey(): string
    {
        return $this->encryptionKey;
    }

    /**
     * Gets the cipher method used for encryption.
     * This method returns the cipher method used for encrypting cache items.
     *
     * @return string The cipher method.
     */
    public function getCipher(): string
    {
        return $this->cipher;
    }
    
    /**
     * Gets the default time-to-live (TTL) for cache items.
     * This method returns the default TTL in seconds.
     *
     * @return int The default TTL in seconds.
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Gets the time-to-live (TTL) for cache items.
     * This method returns the TTL in seconds.
     *
     * @return int The TTL in seconds.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Destructor for the FileCache class.
     *
     * This method is called when the object is destroyed.
     * It can be used to perform cleanup tasks, such as purging expired cache items.
     * However, it's generally better to call purge() explicitly when needed,
     * as the destructor may not be called immediately or at all in some cases.
     */
    public function __destruct()
    {
        $this->purge();
        $this->ttl = $this->defaultTtl; // Reset TTL to default
        $this->encryptionKey = ''; // Clear encryption key
        $this->cipher = 'aes-256-cbc'; // Reset cipher to default
        $this->cacheDir = ''; // Clear cache directory
        // Note: The destructor is not guaranteed to be called in all cases,
        // so it's better to call purge() explicitly when needed.
        // This is just a cleanup step to ensure no sensitive data remains in memory.
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles(); // Suggest garbage collection
        }
    }
}
