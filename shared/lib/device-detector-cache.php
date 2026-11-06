<?php
/**
 * APCu cache for Matomo DeviceDetector (ROP-348, 2026-11-06).
 *
 * DeviceDetector parses its regex YAML on every request unless given a cache: ~620 ms per page view on
 * this box. With APCu the parsed regexes persist across requests and a parse takes ~25 ms.
 * Use: $dd = new DeviceDetector($ua); if ($c = \RpShared\device_detector_cache()) { $dd->setCache($c); }
 * Falls back to nothing (DeviceDetector's per-request static cache) if the apcu extension is missing.
 */
namespace RpShared;

final class ApcuCache implements \DeviceDetector\Cache\CacheInterface
{
    public function fetch(string $id)
    {
        $ok = false;
        $v = apcu_fetch($id, $ok);
        return $ok ? $v : false;
    }
    public function contains(string $id): bool { return apcu_exists($id); }
    public function save(string $id, $data, int $lifeTime = 0): bool { return (bool) apcu_store($id, $data, $lifeTime); }
    public function delete(string $id): bool { return (bool) apcu_delete($id); }
    public function flushAll(): bool { return apcu_clear_cache(); }
}

function device_detector_cache(): ?\DeviceDetector\Cache\CacheInterface
{
    return (function_exists('apcu_enabled') && apcu_enabled()) ? new ApcuCache() : null;
}
