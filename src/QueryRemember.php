<?php declare(strict_types=1);

namespace Eusonlito\QueryRemember;

use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Traits\ForwardsCalls;
use RuntimeException;

class QueryRemember
{
    use ForwardsCalls;

    /**
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder
     * @param \Illuminate\Contracts\Cache\Repository $cache
     * @param array $config
     * @param int|\DateTimeInterface|\DateInterval|null $ttl
     * @param ?string $key
     * @param int $wait = 0
     */
    public function __construct(
        protected QueryBuilder|EloquentBuilder $builder,
        protected Repository $cache,
        protected array $config,
        protected int|DateTimeInterface|DateInterval|null $ttl = null,
        protected ?string $key = null,
        protected int $wait = 0
    ) {
    }

    /**
     * @return string
     */
    protected function key(): string
    {
        return $this->key ?? $this->cacheKeyDefault();
    }

    /**
     * @return string
     */
    protected function cacheKeyDefault(): string
    {
        return $this->config['prefix'].md5($this->builder->toSql().'|'.serialize($this->builder->getBindings()));
    }

    /**
     * @return int
     */
    protected function ttl(): int
    {
        return $this->ttl ?? $this->config['ttl'];
    }

    /**
     * @param string $method
     * @param array $arguments
     *
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->cache()->remember(
            $this->key(),
            $this->ttl(),
            fn () => $this->wait
                ? $this->getLockResult($method, $arguments)
                : $this->getResult($method, $arguments)
        );
    }

    /**
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected function cache(): Repository
    {
        if ($this->isTaggable() === false) {
            return $this->cache;
        }

        return $this->cache->tags($this->tags());
    }

    /**
     * @return bool
     */
    protected function isTaggable(): bool
    {
        return $this->cache->getStore() instanceof TaggableStore;
    }

    /**
     * @return array
     */
    protected function tags(): array
    {
        return array_filter([$this->tagGlobal(), $this->tagPrefix()]);
    }

    /**
     * @return ?string
     */
    protected function tagGlobal(): ?string
    {
        return $this->config['tag'];
    }

    /**
     * @return ?string
     */
    protected function tagPrefix(): ?string
    {
        if (empty($tag = $this->config['tag'])) {
            return null;
        }

        return $this->tagPrefixModel($tag)
            ?: $this->tagPrefixBuilder($tag);
    }

    /**
     * @param string $tag
     *
     * @return ?string
     */
    protected function tagPrefixBuilder(string $tag): ?string
    {
        if ($from = $this->builder->from) {
            return $tag.'|'.$from;
        }

        return null;
    }

    /**
     * @param string $tag
     *
     * @return ?string
     */
    protected function tagPrefixModel(string $tag): ?string
    {
        if (!($this->builder instanceof EloquentBuilder)) {
            return null;
        }

        $model = $this->builder->getModel();

        if (method_exists($model, 'getCacheTag')) {
            return $model->getCacheTag();
        }

        if (method_exists($model, 'getCacheTagSuffix')) {
            return $tag.'|'.$model->getCacheTagSuffix();
        }

        return $tag.'|'.$model->getTable();
    }

    /**
     * @param string $method
     * @param array $arguments
     *
     * @return mixed
     */
    protected function getLockResult(string $method, array $arguments): mixed
    {
        return $this->cache()
            ->lock($this->key(), $this->wait)
            ->block($this->wait, fn () => $this->getResult($method, $arguments));
    }

    /**
     * @param string $method
     * @param array $arguments
     *
     * @return mixed
     */
    protected function getResult(string $method, array $arguments): mixed
    {
        $result = $this->forwardCallTo($this->builder, $method, $arguments);

        if (($result instanceof QueryBuilder) || ($result instanceof EloquentBuilder)) {
            throw new RuntimeException("The `remember()` method call is not before query execution: [$method] called.");
        }

        return $result;
    }
}
