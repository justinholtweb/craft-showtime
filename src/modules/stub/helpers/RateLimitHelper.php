<?php

namespace justinholtweb\stub\helpers;

use Craft;

/**
 * Cache-backed sliding-window rate limiter for anonymous endpoints. Counts requests per
 * (bucket, IP) over a configurable window; returns false when the limit is exceeded.
 */
class RateLimitHelper
{
    public static function check(string $bucket, int $maxRequests, int $windowSeconds): bool
    {
        $ip = Craft::$app->getRequest()->getUserIP();
        if (!$ip) {
            return true;
        }

        $cache = Craft::$app->getCache();
        $key = "stub:rl:{$bucket}:{$ip}";

        $count = (int)$cache->get($key);
        if (!self::shouldAllow($ip, $count, $maxRequests)) {
            return false;
        }

        $cache->set($key, $count + 1, $windowSeconds);
        return true;
    }

    /**
     * Pure rate-limit decision, split out from the cache/IP plumbing so the branch logic
     * can be unit-tested. A missing IP bypasses the limit; otherwise the request is allowed
     * only while the prior count is below the maximum.
     */
    public static function shouldAllow(?string $ip, int $currentCount, int $maxRequests): bool
    {
        if (!$ip) {
            return true;
        }

        return $currentCount < $maxRequests;
    }
}
