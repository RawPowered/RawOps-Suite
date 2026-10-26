<?php
// Lightweight file cache helper with flock and JSON encoding
// Usage: $data = cache_get_or_set_json($key, 600, function() { return [ 'k' => 'v' ]; });

if (!function_exists('cache_get_or_set_json')) {
    function cache_get_or_set_json(string $cacheKey, int $ttlSeconds, callable $computeFn) {
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $cacheKey) . '.json';
        $now = time();

        // Serve fresh cache if valid
        if (is_file($path)) {
            $mtime = @filemtime($path) ?: 0;
            if ($mtime + $ttlSeconds > $now) {
                $content = @file_get_contents($path);
                if ($content !== false) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) { return $decoded; }
                }
            }
        }

        // Compute new value with file lock to prevent stampede
        $fp = @fopen($path . '.lock', 'c+');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                // Recheck after acquiring lock
                if (is_file($path)) {
                    $mtime = @filemtime($path) ?: 0;
                    if ($mtime + $ttlSeconds > $now) {
                        $content = @file_get_contents($path);
                        if ($content !== false) {
                            flock($fp, LOCK_UN);
                            fclose($fp);
                            $decoded = json_decode($content, true);
                            if (is_array($decoded)) { return $decoded; }
                        }
                    }
                }

                $value = $computeFn();
                // Minimal JSON cleansing: UTF-8 enforce, unescaped slashes, unicode
                $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json !== false) {
                    @file_put_contents($path, $json, LOCK_EX);
                }
                flock($fp, LOCK_UN);
                fclose($fp);
                return $value;
            }
            fclose($fp);
        }

        // Fallback: compute without cache
        return $computeFn();
    }
}


