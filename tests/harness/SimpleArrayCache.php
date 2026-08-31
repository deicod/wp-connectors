<?php
/**
 * Minimal PSR-16 array cache for tests.
 *
 * Used to prove connector cache behavior against a configured PSR-16 cache
 * (AiClient::setCache()) without any external dependency; entries are
 * exposed for direct inspection and poisoning.
 *
 * This class is test infrastructure only; it is never shipped in a plugin.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

final class SimpleArrayCache implements CacheInterface
{
    /**
     * Stored values: key => value (exposed for test assertions).
     *
     * @var array<string, mixed>
     */
    public $entries = array();

    public function get($key, $default = null)
    {
        $this->assertKey($key);

        return array_key_exists($key, $this->entries) ? $this->entries[ $key ] : $default;
    }

    public function set($key, $value, $ttl = null)
    {
        $this->assertKey($key);
        $this->entries[ $key ] = $value;

        return true;
    }

    public function delete($key)
    {
        $this->assertKey($key);
        unset($this->entries[ $key ]);

        return true;
    }

    public function clear()
    {
        $this->entries = array();

        return true;
    }

    public function getMultiple($keys, $default = null)
    {
        $result = array();
        foreach ($keys as $key) {
            $result[ $key ] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple($values, $ttl = null)
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys)
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key)
    {
        $this->assertKey($key);

        return array_key_exists($key, $this->entries);
    }

    /**
     * Rejects non-string/empty keys the way real PSR-16 implementations do.
     *
     * @param mixed $key Cache key.
     * @return void
     */
    private function assertKey($key)
    {
        if (! is_string($key) || '' === $key) {
            throw new InvalidArgumentException('Cache keys must be non-empty strings.');
        }
    }
}
