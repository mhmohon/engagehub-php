<?php

namespace EngageHub\Http;

use EngageHub\Exceptions\NetworkException;

/**
 * Decorates any Transport with retries on 429 / 5xx / network failures:
 * exponential backoff with full jitter, honoring Retry-After, capped at
 * MAX_SLEEP_MS per wait. Everything else passes straight through — mapping
 * statuses to exceptions is EngageHubClient's job.
 */
final class RetryingTransport implements Transport
{
    private const int MAX_SLEEP_MS = 8000;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param  callable(int): void|null  $sleeper  receives milliseconds; injectable for tests
     */
    public function __construct(
        private readonly Transport $inner,
        private readonly int $maxRetries = 3,
        private readonly int $baseMs = 500,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $ms) => usleep($ms * 1000);
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->inner->send($method, $url, $headers, $body);
            } catch (NetworkException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }

                ($this->sleeper)($this->backoffMs($attempt, null));
                $attempt++;

                continue;
            }

            if (! $this->shouldRetry($response->status) || $attempt >= $this->maxRetries) {
                return $response;
            }

            ($this->sleeper)($this->backoffMs($attempt, $response->header('Retry-After')));
            $attempt++;
        }
    }

    private function shouldRetry(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function backoffMs(int $attempt, ?string $retryAfter): int
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return min((int) $retryAfter * 1000, self::MAX_SLEEP_MS);
        }

        $ceiling = min($this->baseMs * (2 ** $attempt), self::MAX_SLEEP_MS);

        // Full jitter: spread retries so bursts do not re-collide.
        return random_int(0, $ceiling);
    }
}
