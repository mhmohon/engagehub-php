<?php

namespace EngageHub\Tests;

use EngageHub\Buffer\EventBuffer;
use EngageHub\Config;
use EngageHub\EngageHubClient;
use EngageHub\Support\Uuid;
use PHPUnit\Framework\TestCase;

class BufferAndUuidTest extends TestCase
{
    public function test_buffer_flushes_manually_and_clears(): void
    {
        $transport = new FakeTransport;
        $transport->queueJson(202, ['accepted' => 2, 'duplicates' => 0, 'rejected' => 0]);

        $client = new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1'),
            $transport,
            retrySleeper: static fn () => null,
        );

        $buffer = new EventBuffer($client->events());
        $buffer->add(['name' => 'a']);
        $buffer->add(['name' => 'b']);

        $this->assertSame(2, $buffer->count());
        $this->assertCount(0, $transport->requests);

        $result = $buffer->flush();

        $this->assertSame(2, $result->accepted);
        $this->assertSame(0, $buffer->count());
        $this->assertNull($buffer->flush());
    }

    public function test_buffer_auto_flushes_at_500(): void
    {
        $transport = new FakeTransport;
        $transport->queueJson(202, ['accepted' => 500, 'duplicates' => 0, 'rejected' => 0]);

        $client = new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1'),
            $transport,
            retrySleeper: static fn () => null,
        );

        $buffer = new EventBuffer($client->events());

        $result = null;
        for ($i = 0; $i < 500; $i++) {
            $result = $buffer->add(['name' => 'e'.$i]);
        }

        $this->assertNotNull($result);
        $this->assertSame(500, $result->accepted);
        $this->assertSame(0, $buffer->count());
    }

    public function test_uuid_v4_shape_and_uniqueness(): void
    {
        $seen = [];

        for ($i = 0; $i < 100; $i++) {
            $uuid = Uuid::v4();

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid,
            );

            $seen[$uuid] = true;
        }

        $this->assertCount(100, $seen);
    }
}
