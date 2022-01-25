Moved to a new repository that manages the cache in a much more efficient way: https://github.com/eusonlito/laravel-database-cache

# Query Remember

Based on https://github.com/DarkGhostHunter/RememberableQuery, but with tags and more focused on Eloquent.

```php
Articles::latest('published_at')->take(10)->remember()->get();
```

## Requirements

* PHP 8.0
* Laravel 8.x

## Installation

You can install the package via composer:

```bash
composer require eusonlito/query-remember

php artisan vendor:publish --tag=eusonlito-query-remember
```

## Differences from DarkGhostHunter/RememberableQuery

* TTL, driver and enabled is configurable using `config/query-remember.php` configuration.
* Database cache is grouped in tags using the global config.
* Models can use the `getCacheTag` and `getCacheTagSuffix` methods to custom models tags .

## Usage

Just use the `remember()` method to remember a Query result **before the execution**. That's it. The method automatically remembers the result for 60 seconds.

If you are using the default config, this cache will be stored inside `['database', 'database|articles']` tags.

```php
use Illuminate\Support\Facades\DB;
use App\Models\Article;

$database = DB::table('articles')->latest('published_at')->take(10)->remember()->get();

$eloquent = Article::latest('published_at')->take(10)->remember()->get();
```

The next time you call the **same** query, the result will be retrieved from the cache instead of running the SQL statement in the database, even if the result is `null` or `false`.

> The `remember()` will throw an error if you build a query instead of executing it.

### Time-to-live

By default, queries are remembered by 60 seconds, but you're free to use any length, `Datetime`, `DateInterval` or Carbon instance.

```php
DB::table('articles')->latest('published_at')->take(10)->remember(60 * 60)->get();

Article::latest('published_at')->take(10)->remember(now()->addHour())->get();
```

### Custom Cache Key

The auto-generated cache key is an BASE64-MD5 hash of the SQL query and its bindings, which avoids any collision with other queries while keeping the cache key short. 

If you are using the default config, this cache will be stored inside `['database', 'database|articles']` tags with the key `latest_articles`.

```php
Article::latest('published_at')->take(10)->remember(30, 'latest_articles')->get();
```

### Cache Lock (data races)

On multiple processes, the Query may be executed multiple times until the first process is able to store the result in the cache, specially when these take more than 1 second. To avoid this, set the `wait` parameter with the number of seconds to hold the lock acquired.

```php
Article::latest('published_at')->take(200)->remember(wait: 5)->get();
```

The first process will acquire the lock for the given seconds, execute the query and store the result. The next processes will wait until the cache data is available to retrieve the result from there.

> If you need to use this across multiple processes, use the [cache lock](https://laravel.com/docs/cache#managing-locks-across-processes) directly.

### Idempotent queries

While the reason behind remembering a Query is to cache the data retrieved from a database, you can use this to your advantage to create [idempotent](https://en.wikipedia.org/wiki/Idempotence) queries.

For example, you can make this query only execute once every day for a given user ID.

```php
$key = auth()->user()->getAuthIdentifier();

Article::whereKey(54)->remember(now()->addHour(), 'user:'.$key)->increment('unique_views');
```

Subsequent executions of this query won't be executed at all until the cache expires, so in the above example we have surprisingly created a "unique views" mechanic.

## Operations are **NOT** commutative

Altering the Builder methods order will change the auto-generated cache key hash. Even if they are _visually_ the same, the order of statements makes the hash completely different.

For example, given two similar queries in different parts of the application, these both will **not** share the same cached result:

```php
User::whereName('Joe')->whereAge(20)->remember()->first();
User::whereAge(20)->whereName('Joe')->remember()->first();
```

To ensure you're hitting the same cache on similar queries, use a [custom cache key](#custom-cache-key). With this, all queries using the same key will share the same cached result:

```php
User::whereName('Joe')->whereAge(20)->remember(60, 'find_joe')->first();
User::whereAge(20)->whereName('Joe')->remember(60, 'find_joe')->first();
```

This will allow you to even retrieve the data outside the query, by just asking directly to the cache.

```php
$joe = Cache::tags(['database', 'database|users'])->get('find_joe');
```

Remember that you need to pass the same ordered list of tags to the `tags` method as when cache was stored. Always use `['database', 'database|XXX']` when `XXX` is the table name related with the query.

## Tags

This package tag caches with two different [tags](https://laravel.com/docs/8.x/cache#cache-tags) (only supported by `redis` and `memcached`)

* `database` is the global for all database cache.
* `database|XXXX` is the tag for every different table. Table name will be set with the `getTable` method on models or with the `from` string on `Query Builder`.

## Custom tags on models

The models can include two methods to create custom tags:

* `getCacheTag` will tag the cache with the global tag `database` and a second tag with the string returned by this method.
* `getCacheTagSuffix` will tag the cache with the global tag `database` and a second tag appending to `database|` the string returned by this method.

## Flush caches

You can flush all database caches or only caches related with only one table:

```php
// Flush all database cache
Cache::tags('database')->flush();

// Flush only users table cache
Cache::tags('database|users')->flush();
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
