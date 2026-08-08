<?php

declare(strict_types=1);

// @oagen-ignore-file

namespace Tests;

use PHPUnit\Framework\TestCase;
use Rafflesia\Exception\HomologySearchWaitTimeoutException;
use Rafflesia\Exception\RafflesiaException;
use Rafflesia\Resource\HomologyQuery;
use Rafflesia\Resource\HomologySearch;
use Rafflesia\Resource\HomologySearchStatus;

final class HomologyWaitTest extends TestCase
{
    use TestHelper;

    /** @return array<string, mixed> */
    private function envelope(string $status, string $id = 'hsr_test'): array
    {
        $fixture = $this->loadFixture('envelope_homology_search');
        $fixture['ok'] = true;
        $fixture['data']['id'] = $id;
        $fixture['data']['status'] = $status;
        return $fixture;
    }

    public function testWaitPollsUntilTerminalAndInvokesOnUpdate(): void
    {
        $client = $this->createMockClient([
            ['status' => 200, 'body' => $this->envelope('running')],
            ['status' => 200, 'body' => $this->envelope('succeeded')],
        ]);

        $updates = [];
        $search = $client->homologySearches()->wait('hsr_test', [
            'poll_interval_ms' => 1,
            'on_update' => function (HomologySearch $snapshot) use (&$updates): void {
                $updates[] = $snapshot->status;
            },
        ]);

        self::assertSame(HomologySearchStatus::Succeeded, $search->status);
        self::assertSame(
            [HomologySearchStatus::Running, HomologySearchStatus::Succeeded],
            $updates,
        );
        $request = $this->getLastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertStringEndsWith('v1/homology/searches/hsr_test', $request->getUri()->getPath());
    }

    public function testWaitTimesOutWithNamedException(): void
    {
        $client = $this->createMockClient([
            ['status' => 200, 'body' => $this->envelope('running')],
        ]);

        try {
            $client->homologySearches()->wait('hsr_test', [
                'timeout_ms' => 0,
                'poll_interval_ms' => 1,
            ]);
            self::fail('Expected the timeout exception.');
        } catch (HomologySearchWaitTimeoutException $exception) {
            self::assertSame('hsr_test', $exception->homologySearchId);
            self::assertSame(0, $exception->timeoutMs);
            self::assertStringContainsString('continues server-side', $exception->getMessage());
        }
        // Local timeout never cancels the durable search: only the GET polled.
        self::assertSame('GET', $this->getLastRequest()->getMethod());
    }

    public function testWaitSurfacesEnvelopeErrorAsApiError(): void
    {
        $errorEnvelope = $this->loadFixture('envelope_homology_search');
        unset($errorEnvelope['data']);
        $errorEnvelope['ok'] = false;
        $errorEnvelope['error'] = [
            'type' => 'invalid_request_error',
            'code' => 'search_expired',
            'message' => 'search expired',
            'doc_url' => 'https://rafflesia.ai/docs/errors/search_expired',
        ];
        $client = $this->createMockClient([[
            'status' => 200,
            'body' => $errorEnvelope,
        ]]);

        try {
            $client->homologySearches()->wait('hsr_test');
            self::fail('Expected the API error.');
        } catch (HomologySearchWaitTimeoutException $exception) {
            self::fail('Expected the base API error, not the timeout exception.');
        } catch (RafflesiaException $exception) {
            self::assertSame('search expired', $exception->getMessage());
            self::assertSame('search_expired', $exception->errorCode);
        }
    }

    public function testSubscribeCreatesWithDefaultPreferThenWaits(): void
    {
        $client = $this->createMockClient([
            ['status' => 202, 'body' => $this->envelope('queued')],
            ['status' => 200, 'body' => $this->envelope('succeeded')],
        ]);

        $search = $client->homologySearches()->subscribe(
            [
                'idempotencyKey' => 'idem_wait',
                'query' => new HomologyQuery(sequence: 'MTEYK'),
            ],
            ['poll_interval_ms' => 1],
        );

        self::assertSame(HomologySearchStatus::Succeeded, $search->status);
        self::assertSame('GET', $this->getLastRequest()->getMethod());
        $createRequest = $this->requests[0];
        self::assertSame('POST', $createRequest->getMethod());
        self::assertSame('idem_wait', $createRequest->getHeaderLine('Idempotency-Key'));
        self::assertSame('wait=10', $createRequest->getHeaderLine('Prefer'));
    }

    public function testSubscribeReturnsImmediatelyWhenCreateIsTerminal(): void
    {
        $client = $this->createMockClient([
            ['status' => 201, 'body' => $this->envelope('succeeded')],
        ]);

        $search = $client->homologySearches()->subscribe([
            'idempotencyKey' => 'idem_now',
            'prefer' => 'respond-async',
            'query' => new HomologyQuery(sequence: 'MTEYK'),
        ]);

        self::assertSame(HomologySearchStatus::Succeeded, $search->status);
        $request = $this->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        // Caller's explicit Prefer wins over the wait=10 default.
        self::assertSame('respond-async', $request->getHeaderLine('Prefer'));
    }
}
