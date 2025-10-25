<?php

namespace PKP\core;

use DateInterval;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as LaravelEventServiceProvider;
use Illuminate\Support\Facades\Cache;
use SplFileInfo;

class EventServiceProvider extends LaravelEventServiceProvider
{
    /** Max lifetime for the event discovery cache */
    protected const MAX_CACHE_LIFETIME = '1 day';

    public function getEvents()
    {
        $expiration = DateInterval::createFromDateString(static::MAX_CACHE_LIFETIME);
        $events = Cache::remember(static::getCacheKey(), $expiration, fn () => $this->discoveredEvents());

        return array_merge_recursive(
            $events,
            $this->listens()
        );
    }

    public function shouldDiscoverEvents()
    {
        return true;
    }

    public function discoverEvents()
    {
        // Adapt classes naming convention
        $discoverEvents = new class () extends DiscoverEvents {
            protected static function classFromFile(SplFileInfo $file, $basePath): string
            {
                return Core::classFromFile($file);
            }
        };

        // ✅ Removed $this — now uses static:: instead
        $discoverEventsWithin = static::discoverEventsWithin();

        return collect($discoverEventsWithin)
            ->reject(function ($directory) {
                return !is_dir($directory);
            })
            ->reduce(function ($discovered, $directory) use ($discoverEvents) {
                return array_merge_recursive(
                    $discovered,
                    $discoverEvents::within($directory, base_path())
                );
            }, []);
    }

    // ✅ Made this method static so it can be called without $this
    protected static function discoverEventsWithin()
    {
        return [
            app()->basePath('lib/pkp/classes/observers/listeners'),
            app()->basePath('classes/observers/listeners'),
        ];
    }

    public static function clearCache(): void
    {
        Cache::forget(static::getCacheKey());
    }

    private static function getCacheKey(): string
    {
        return __METHOD__ . static::MAX_CACHE_LIFETIME;
    }
}
