<?php

namespace App\Domain\Shared\Services;

use App\Support\TaggedCache;

class CircuitBreaker
{
    private string $serviceName;

    private int $maxFailures;

    private int $decayMinutes;

    public function __construct(string $serviceName, int $maxFailures = 10, int $decayMinutes = 5)
    {
        $this->serviceName = $serviceName;
        $this->maxFailures = $maxFailures;
        $this->decayMinutes = $decayMinutes;
    }

    private function getCacheKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:failures";
    }

    public function recordFailure(): void
    {
        $key = $this->getCacheKey();

        $failures = TaggedCache::for(['circuit_breaker'])->get($key, 0);
        $failures++;

        TaggedCache::for(['circuit_breaker'])->put($key, $failures, now()->addMinutes($this->decayMinutes));
        
        // Record last failure time for half-open check
        TaggedCache::for(['circuit_breaker'])->put($this->getCacheKey() . '_time', time(), now()->addMinutes($this->decayMinutes));
    }

    public function recordSuccess(): void
    {
        TaggedCache::for(['circuit_breaker'])->forget($this->getCacheKey());
        TaggedCache::for(['circuit_breaker'])->forget($this->getCacheKey() . '_time');
    }

    public function isOpen(): bool
    {
        $failures = TaggedCache::for(['circuit_breaker'])->get($this->getCacheKey(), 0);

        if ($failures >= $this->maxFailures) {
            // Check if we are in half-open state (enough time has passed to retry one request)
            $lastFailure = TaggedCache::for(['circuit_breaker'])->get($this->getCacheKey() . '_time', 0);
            if ((time() - $lastFailure) > ($this->decayMinutes * 60)) {
                return false; // Half-open, let one request through
            }
            return true;
        }

        return false;
    }

    /**
     * Execute a closure through the circuit breaker.
     * 
     * @param callable $action The external API call to make
     * @return mixed
     * @throws \Exception
     */
    public function execute(callable $action)
    {
        if ($this->isOpen()) {
            throw new \Exception("Circuit Breaker OPEN: {$this->serviceName} is currently unreachable. Fail-fast triggered.");
        }

        try {
            $result = $action();
            $this->recordSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->recordFailure();
            throw $e;
        }
    }
}
