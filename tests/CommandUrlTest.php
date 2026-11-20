<?php

namespace EngageHub\Tests;

use EngageHub\Config;
use EngageHub\EngageHubClient;
use PHPUnit\Framework\TestCase;

class CommandUrlTest extends TestCase
{
    private function client(?string $commandUrl, FakeTransport $transport): EngageHubClient
    {
        return new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1', commandUrl: $commandUrl),
            $transport,
            retrySleeper: static fn () => null,
        );
    }

    public function test_the_command_url_is_sent_with_every_request(): void
    {
        $transport = (new FakeTransport)->queueJson(200, ['ok' => true]);
        $this->client('https://app.test/engagehub/commands', $transport)->ping();

        $this->assertSame('https://app.test/engagehub/commands', $transport->requests[0]['headers']['EngageHub-Command-Url']);
    }

    public function test_no_command_url_sends_no_header(): void
    {
        $transport = (new FakeTransport)->queueJson(200, ['ok' => true]);
        $this->client(null, $transport)->ping();

        $this->assertArrayNotHasKey('EngageHub-Command-Url', $transport->requests[0]['headers']);
    }
}
