<?php

namespace EngageHub\Tests;

use EngageHub\Config;
use EngageHub\Exceptions\AuthenticationException;
use EngageHub\Exceptions\AuthorizationException;
use EngageHub\Exceptions\NetworkException;
use EngageHub\Exceptions\RateLimitException;
use EngageHub\Exceptions\ServerException;
use EngageHub\Exceptions\ValidationException;
use EngageHub\EngageHubClient;
use PHPUnit\Framework\TestCase;

class RetryAndErrorsTest extends TestCase
{
    private FakeTransport $transport;

    /** @var list<int> */
    private array $sleeps = [];

    private EngageHubClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
        $this->client = new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1', maxRetries: 2),
            $this->transport,
            retrySleeper: fn (int $ms) => $this->sleeps[] = $ms,
        );
    }

    public function test_retries_5xx_then_succeeds(): void
    {
        $this->transport->queueJson(500, ['message' => 'boom']);
        $this->transport->queueJson(202, ['accepted' => 1, 'duplicates' => 0, 'rejected' => 0]);

        $result = $this->client->events()->track('signup');

        $this->assertTrue($result->accepted());
        $this->assertCount(2, $this->transport->requests);
        $this->assertCount(1, $this->sleeps);
    }

    public function test_retries_exhausted_5xx_throws_server_exception(): void
    {
        $this->transport->queueJson(500, ['message' => 'boom']);

        $this->expectException(ServerException::class);

        try {
            $this->client->events()->track('signup');
        } finally {
            // maxRetries=2 → 3 total attempts.
            $this->assertCount(3, $this->transport->requests);
        }
    }

    public function test_429_honors_retry_after_and_throws_rate_limit_exception(): void
    {
        $this->transport->queueJson(429, ['message' => 'slow down'], ['retry-after' => '7']);

        try {
            $this->client->events()->track('signup');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(7, $e->retryAfter());
        }

        $this->assertSame([7000, 7000], $this->sleeps);
    }

    public function test_network_errors_are_retried_then_thrown(): void
    {
        $this->transport->queue(new NetworkException('dns fail'));

        $this->expectException(NetworkException::class);

        try {
            $this->client->ping();
        } finally {
            $this->assertCount(3, $this->transport->requests);
        }
    }

    public function test_401_throws_authentication_exception_without_retry(): void
    {
        $this->transport->queueJson(401, ['message' => 'Invalid API key.']);

        try {
            $this->client->ping();
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('Invalid API key.', $e->getMessage());
        }

        $this->assertCount(1, $this->transport->requests);
    }

    public function test_403_throws_authorization_exception(): void
    {
        $this->transport->queueJson(403, ['message' => 'This API key lacks the contacts:write scope.']);

        $this->expectException(AuthorizationException::class);
        $this->client->contacts()->upsert(['external_id' => 'u1']);
    }

    public function test_422_throws_validation_exception_with_errors(): void
    {
        $this->transport->queueJson(422, [
            'message' => 'The batch field is required.',
            'errors' => ['batch' => ['The batch field is required.']],
        ]);

        try {
            $this->client->contacts()->upsert(['first_name' => 'NoIdentity']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch', $e->errors());
        }
    }
}
