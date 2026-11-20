<?php

namespace EngageHub\Tests;

use EngageHub\Config;
use EngageHub\EngageHubClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RecordsTest extends TestCase
{
    private FakeTransport $transport;

    private EngageHubClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
        $this->client = new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1'),
            $this->transport,
            retrySleeper: static fn () => null,
        );
    }

    public function test_subscriptions_plans_and_objects_post_to_their_endpoints(): void
    {
        $this->transport->queueJson(202, ['accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0, 'results' => [['index' => 0, 'status' => 'created', 'external_id' => 'sub_1']]]);

        $result = $this->client->subscriptions()->upsert([
            'external_id' => 'sub_1',
            'contact' => ['external_id' => '42'],
            'plan' => ['external_id' => 'pro', 'name' => 'Pro'],
            'status' => 'active',
        ]);
        $this->client->plans()->upsert(['external_id' => 'pro', 'name' => 'Pro']);
        $this->client->objects('book')->upsert(['external_id' => 'b1', 'contact' => ['external_id' => '42']]);

        $this->assertSame('created', $result->status);
        $this->assertSame('sub_1', $result->identity);
        $this->assertSame([
            'https://engagehub.test/api/v1/subscriptions/sync',
            'https://engagehub.test/api/v1/plans/sync',
            'https://engagehub.test/api/v1/objects/book/sync',
        ], array_column($this->transport->requests, 'url'));
    }

    public function test_backfill_batches_say_so_and_chunk_at_500(): void
    {
        $this->transport->queueJson(202, ['accepted' => 500, 'created' => 500, 'updated' => 0, 'rejected' => 0, 'results' => []]);
        $this->transport->queueJson(202, ['accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0, 'results' => []]);

        $records = array_map(static fn (int $i): array => ['external_id' => "s{$i}", 'contact' => ['external_id' => '1'], 'status' => 'active'], range(1, 501));
        $result = $this->client->subscriptions()->upsertBatch($records, backfill: true);

        $this->assertSame(501, $result->created);
        $this->assertSame('backfill', json_decode($this->transport->requests[0]['body'], true)['mode']);
        $this->assertCount(1, json_decode($this->transport->requests[1]['body'], true)['batch']);
    }

    public function test_deletes_encode_ids_and_map_404_to_false(): void
    {
        $this->transport->queueJson(200, ['status' => 'deleted']);
        $this->transport->queueJson(404, ['message' => 'No']);

        $this->assertTrue($this->client->objects('book')->delete('b/1'));
        $this->assertFalse($this->client->subscriptions()->delete('nope'));
        $this->assertSame('https://engagehub.test/api/v1/objects/book/b%2F1', $this->transport->requests[0]['url']);
        $this->assertSame('DELETE', $this->transport->requests[1]['method']);
    }

    public function test_object_types_are_checked_before_any_request(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->objects('Books!');
    }
}
