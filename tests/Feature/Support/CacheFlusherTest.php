<?php

use App\Support\CacheFlusher;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

it('leaves entries alone on a store that cannot scan its keyspace', function () {
    expect(Cache::store()->getStore())->not->toBeInstanceOf(RedisStore::class);

    Cache::put('page:one', 'kept', 60);

    CacheFlusher::forgetMatching('page:*');

    expect(Cache::get('page:one'))->toBe('kept');
});
