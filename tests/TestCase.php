<?php

namespace Tests;

use App\Models\Archetype;
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
     *
     * The archetype roster is memoised in a static for the same reason it is
     * memoised at all — stat resolution reads it constantly — and a static
     * outlives a rolled-back transaction, so it is dropped here too.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('');
        Archetype::forgetRoster();
    }

    //
}
