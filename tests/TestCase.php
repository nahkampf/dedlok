<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    /**
     * Start every test with an empty cache.
     *
     * The array cache store persists for the whole process, so without this
     * one test leaks into the next: rate-limiter counters carry over (a test
     * asserting a fresh 429 window sees a half-consumed one), and the cached
     * settings and handle pool survive into tests that expect a cold read.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('');
    }

    //
}
