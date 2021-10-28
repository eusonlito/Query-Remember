<?php

namespace Eusonlito\QueryRemember;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider as ServiceProviderVendor;

class ServiceProvider extends ServiceProviderVendor
{
    /**
     * @var array
     */
    protected array $config;

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishConfig();
        $this->setup();
    }

    /**
     * @return void
     */
    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/query-remember.php' => config_path('query-remember.php'),
        ], 'eusonlito-query-remember');
    }

    /**
     * @return void
     */
    protected function setup(): void
    {
        if ($this->config('enabled') === false) {
            return;
        }

        $macro = $this->macro();

        if (QueryBuilder::hasMacro('remember') === false) {
            QueryBuilder::macro('remember', $macro);
        }

        if (EloquentBuilder::hasGlobalMacro('remember') === false) {
            EloquentBuilder::macro('remember', $macro);
        }
    }

    /**
     * @param ?string $key = null
     *
     * @return mixed
     */
    public function config(?string $key = null): mixed
    {
        static $config;

        $config ??= $this->configDefault();

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? null;
    }

    /**
     * @return array
     */
    protected function configDefault(): array
    {
        return config('query-remember', []) + require __DIR__.'/../config/query-remember.php';
    }

    /**
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function cache(): Repository
    {
        return resolve('cache')->store($this->config('cache'));
    }

    /**
     * @return \Closure
     */
    public function macro(): Closure
    {
        $cache = $this->cache();
        $config = $this->config();

        return function (
            int|DateTimeInterface|DateInterval|null $ttl = null,
            ?string $key = null,
            int $wait = 0
        ) use ($cache, $config): QueryRemember {
            return new QueryRemember($this, $cache, $config, $ttl, $key, $wait);
        };
    }
}
