<?php

namespace EngageHub;

use EngageHub\Exceptions\AuthenticationException;
use EngageHub\Exceptions\AuthorizationException;
use EngageHub\Exceptions\EngageHubException;
use EngageHub\Exceptions\NotFoundException;
use EngageHub\Exceptions\RateLimitException;
use EngageHub\Exceptions\ServerException;
use EngageHub\Exceptions\ValidationException;
use EngageHub\Http\CurlTransport;
use EngageHub\Http\Response;
use EngageHub\Http\RetryingTransport;
use EngageHub\Http\Transport;
use EngageHub\Resources\Contacts;
use EngageHub\Resources\Objects;
use EngageHub\Resources\Plans;
use EngageHub\Resources\Payments;
use EngageHub\Resources\Subscriptions;
use EngageHub\Resources\Events;

/**
 * Entry point:
 *
 *     $engagehub = new EngageHubClient(new Config(apiKey: 'eh_live_...', baseUrl: 'https://host/api/v1'));
 *     $engagehub->events()->track('signup_completed', externalId: 'user-1', properties: ['plan' => 'pro']);
 *     $engagehub->contacts()->upsert(['external_id' => 'user-1', 'email' => 'jane@example.com']);
 *
 * The wire contract is apps/engagehub/docs/data-sync/06-rest-api-and-webhooks.md.
 *
 * Pass any Transport (e.g. Psr18Transport) to route HTTP through your own
 * stack; retries wrap whatever transport is used.
 */
class EngageHubClient
{
    public const VERSION = '0.3.0';

    private readonly Transport $transport;

    private ?Events $events = null;

    private ?Contacts $contacts = null;

    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?callable $retrySleeper = null,
    ) {
        $this->transport = new RetryingTransport(
            $transport ?? new CurlTransport($config->timeout),
            $config->maxRetries,
            $config->retryBaseMs,
            $retrySleeper,
        );
    }

    public function events(): Events
    {
        return $this->events ??= new Events($this);
    }

    public function contacts(): Contacts
    {
        return $this->contacts ??= new Contacts($this);
    }

    public function plans(): Plans
    {
        return new Plans($this);
    }

    public function subscriptions(): Subscriptions
    {
        return new Subscriptions($this);
    }

    public function payments(): Payments
    {
        return new Payments($this);
    }

    /** Objects of one type, e.g. objects('book'). */
    public function objects(string $type): Objects
    {
        return new Objects($this, $type);
    }

    /**
     * Verify the credential and see its group, connection and scopes,
     * without writing any data.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->request('GET', '/ping');
    }

    /**
     * GET /connection — check in and receive any command left for this app in
     * EngageHub (e.g. "Sync existing data": ['type' => 'backfill', 'id' => ...]).
     *
     * @return array{connection?: array<string, mixed>, pending_command?: array<string, mixed>|null}
     */
    public function connection(): array
    {
        return $this->request('GET', '/connection');
    }

    /**
     * POST /connection/sync-runs — report a command's progress so EngageHub can
     * show it: status running, then done or failed, with counts.
     *
     * @param  array{command_id: string, status: string, created?: int, updated?: int, rejected?: int, message?: string|null}  $report
     * @return array<string, mixed>
     */
    public function reportSync(array $report): array
    {
        return $this->request('POST', '/connection/sync-runs', $report);
    }

    /**
     * Perform an API call and return the decoded JSON body. 2xx/202 only —
     * every error status maps to a typed exception.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $json = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->config->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent,
        ];

        $body = null;
        $url = $this->config->baseUrl.$path;

        if ($json !== null) {
            if ($this->config->group !== null) {
                $json['group'] ??= $this->config->group;
            }

            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        } elseif ($this->config->group !== null) {
            $url .= (str_contains($path, '?') ? '&' : '?').'group='.rawurlencode($this->config->group);
        }

        $response = $this->transport->send($method, $url, $headers, $body);

        if ($response->status >= 400) {
            $this->throwFor($response);
        }

        return $response->json();
    }

    private function throwFor(Response $response): never
    {
        $data = $response->json();
        $message = is_string($data['message'] ?? null) && $data['message'] !== ''
            ? $data['message']
            : "EngageHub API responded with HTTP {$response->status}.";

        throw match (true) {
            $response->status === 401 => new AuthenticationException($message),
            $response->status === 403 => new AuthorizationException($message),
            $response->status === 404 => new NotFoundException($message),
            $response->status === 422 => new ValidationException($message, (array) ($data['errors'] ?? [])),
            $response->status === 429 => new RateLimitException(
                $message,
                is_numeric($retryAfter = $response->header('Retry-After')) ? (int) $retryAfter : null,
            ),
            $response->status >= 500 => new ServerException($message),
            default => new EngageHubException($message),
        };
    }
}
