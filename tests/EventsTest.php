<?php

namespace EngageHub\Tests;

use EngageHub\Config;
use EngageHub\EngageHubClient;
use PHPUnit\Framework\TestCase;

class EventsTest extends TestCase
{
    private FakeTransport $transport;

    private EngageHubClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
        $this->client = new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1/'),
            $this->transport,
            retrySleeper: static fn () => null,
        );
    }

    public function test_track_sends_single_event_with_auto_event_id(): void
    {
        $this->transport->queueJson(202, ['accepted' => 1, 'duplicates' => 0, 'rejected' => 0]);

        $result = $this->client->events()->track(
            'signup_completed',
            externalId: 'user-1',
            properties: ['plan' => 'pro'],
        );

        $request = $this->transport->requests[0];
        $json = $this->transport->lastJson();

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://engagehub.test/api/v1/events', $request['url']);
        $this->assertSame('Bearer eh_test_key', $request['headers']['Authorization']);
        $this->assertSame('signup_completed', $json['name']);
        $this->assertSame('user-1', $json['external_id']);
        $this->assertSame(['plan' => 'pro'], $json['properties']);
        $this->assertArrayNotHasKey('payload', $json);
        $this->assertSame('engagehub-php/'.EngageHubClient::VERSION, $request['headers']['User-Agent']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $json['event_id'],
        );

        $this->assertTrue($result->accepted());
        $this->assertSame($json['event_id'], $result->eventId);
    }

    public function test_track_reports_duplicates(): void
    {
        $this->transport->queueJson(202, [
            'accepted' => 0, 'duplicates' => 1, 'rejected' => 0,
            'results' => [['index' => 0, 'name' => 'login', 'status' => 'duplicate', 'event_id' => 'abc']],
        ]);

        $result = $this->client->events()->track('login', eventId: 'abc');

        $this->assertSame('duplicate', $result->status);
        $this->assertFalse($result->accepted());
    }

    public function test_track_batch_chunks_above_500_and_merges_results(): void
    {
        $this->transport->queueJson(202, ['accepted' => 500, 'duplicates' => 0, 'rejected' => 0]);
        $this->transport->queueJson(202, ['accepted' => 2, 'duplicates' => 0, 'rejected' => 0]);

        $events = array_map(
            static fn (int $i): array => ['name' => 'e', 'external_id' => "u{$i}"],
            range(1, 502),
        );

        $result = $this->client->events()->trackBatch($events);

        $this->assertCount(2, $this->transport->requests);
        $this->assertCount(500, json_decode($this->transport->requests[0]['body'], true)['batch']);
        $this->assertCount(2, json_decode($this->transport->requests[1]['body'], true)['batch']);
        $this->assertSame(502, $result->accepted);
        $this->assertCount(502, $result->results);
        // Merged indexes stay globally sequential across chunks.
        $this->assertSame(501, $result->results[501]->index);
    }

    public function test_batch_rejections_do_not_throw_and_are_exposed(): void
    {
        $this->transport->queueJson(202, [
            'accepted' => 1, 'duplicates' => 0, 'rejected' => 1,
            'results' => [
                ['index' => 0, 'name' => 'ok', 'status' => 'accepted'],
                ['index' => 1, 'name' => 'bad', 'status' => 'rejected', 'errors' => ['amount' => 'required']],
            ],
        ]);

        $result = $this->client->events()->trackBatch([
            ['name' => 'ok'],
            ['name' => 'bad'],
        ]);

        $this->assertSame(1, $result->rejected);
        $this->assertCount(1, $result->rejectedItems());
        $this->assertSame('bad', $result->rejectedItems()[0]->name);
    }
}
