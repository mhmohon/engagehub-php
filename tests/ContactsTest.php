<?php

namespace EngageHub\Tests;

use EngageHub\Config;
use EngageHub\EngageHubClient;
use PHPUnit\Framework\TestCase;

class ContactsTest extends TestCase
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

    public function test_upsert_posts_single_contact_and_returns_item_result(): void
    {
        $this->transport->queueJson(202, [
            'accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0,
            'results' => [['index' => 0, 'external_id' => 'user-1', 'email' => 'jane@example.com', 'status' => 'created', 'contact_id' => 17]],
        ]);

        $result = $this->client->contacts()->upsert([
            'external_id' => 'user-1',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame('https://engagehub.test/api/v1/contacts/sync', $this->transport->requests[0]['url']);
        $this->assertSame('user-1', $this->transport->lastJson()['external_id']);
        $this->assertSame('created', $result->status);
        $this->assertSame(17, $result->contactId);
        $this->assertSame('user-1', $result->identity);
    }

    public function test_unchanged_and_stale_counts_are_reported(): void
    {
        $this->transport->queueJson(202, [
            'accepted' => 1, 'created' => 0, 'updated' => 0, 'unchanged' => 1, 'stale' => 1, 'rejected' => 0,
            'results' => [
                ['index' => 0, 'external_id' => 'a', 'status' => 'unchanged'],
                ['index' => 1, 'external_id' => 'b', 'status' => 'stale'],
            ],
        ]);

        $result = $this->client->contacts()->upsertBatch([['external_id' => 'a'], ['external_id' => 'b']]);

        $this->assertSame([1, 1], [$result->unchanged, $result->stale]);
        $this->assertTrue($result->results[0]->accepted());
        $this->assertTrue($result->results[1]->stale());
    }

    public function test_get_and_delete_encode_the_external_id_and_map_404_to_null_or_false(): void
    {
        $this->transport->queueJson(200, ['external_id' => 'u/42', 'status' => 'unsubscribed']);
        $this->transport->queueJson(200, ['status' => 'deleted']);
        $this->transport->queueJson(404, ['message' => 'No contact']);

        $this->assertSame('unsubscribed', $this->client->contacts()->get('u/42')['status']);
        $this->assertTrue($this->client->contacts()->delete('u/42'));
        $this->assertFalse($this->client->contacts()->delete('nobody'));
        $this->assertNull($this->client->contacts()->get('nobody'));

        $this->assertSame('https://engagehub.test/api/v1/contacts/u%2F42', $this->transport->requests[0]['url']);
        $this->assertSame(['GET', 'DELETE'], [$this->transport->requests[0]['method'], $this->transport->requests[1]['method']]);
    }

    public function test_a_group_slug_is_sent_with_every_request_for_workspace_wide_keys(): void
    {
        $client = new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1', group: 'ebookwriter'),
            $this->transport,
            retrySleeper: static fn () => null,
        );
        $this->transport->queueJson(202, ['accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0, 'results' => []]);

        $client->contacts()->upsert(['email' => 'jane@example.com']);
        $client->ping();

        $this->assertSame('ebookwriter', json_decode($this->transport->requests[0]['body'], true)['group']);
        $this->assertSame('https://engagehub.test/api/v1/ping?group=ebookwriter', $this->transport->requests[1]['url']);
    }

    public function test_upsert_batch_chunks_and_merges(): void
    {
        $this->transport->queueJson(202, [
            'accepted' => 500, 'created' => 500, 'updated' => 0, 'rejected' => 0, 'results' => [],
        ]);
        $this->transport->queueJson(202, [
            'accepted' => 1, 'created' => 0, 'updated' => 1, 'rejected' => 0, 'results' => [],
        ]);

        $contacts = array_map(
            static fn (int $i): array => ['external_id' => "u{$i}"],
            range(1, 501),
        );

        $result = $this->client->contacts()->upsertBatch($contacts);

        $this->assertCount(2, $this->transport->requests);
        $this->assertSame(501, $result->accepted);
        $this->assertSame(500, $result->created);
        $this->assertSame(1, $result->updated);
    }

    public function test_ping_returns_decoded_payload(): void
    {
        $this->transport->queueJson(200, ['ok' => true, 'group' => ['slug' => 'ebookwriter'], 'scopes' => ['contacts:write']]);

        $this->assertSame('ebookwriter', $this->client->ping()['group']['slug']);
        $this->assertSame('GET', $this->transport->requests[0]['method']);
        $this->assertSame('https://engagehub.test/api/v1/ping', $this->transport->requests[0]['url']);
    }
}
