<?php

declare(strict_types=1);

// @oagen-ignore-file

namespace Tests;

use PHPUnit\Framework\TestCase;
use Rafflesia\Exception\RafflesiaException;
use Rafflesia\RequestOptions;
use Rafflesia\Resource\Evidence;
use Rafflesia\Resource\Guarantees;
use Rafflesia\Resource\HomologyQuery;
use Rafflesia\Resource\Requirements;

final class HomologyConformanceTest extends TestCase
{
    use TestHelper;

    public function testDirectSingleAndBatchUseDedicatedEndpoints(): void
    {
        $singleFixture = $this->loadFixture('homology_response');
        $singleFixture['future_response_field'] = 'ignored';
        $multiFixture = $this->loadFixture('homology_batch_response');
        $multiFixture['future_response_field'] = 'ignored';
        $client = $this->createMockClient([
            ['status' => 200, 'body' => $singleFixture],
            ['status' => 200, 'body' => $multiFixture],
        ]);
        $query = new HomologyQuery(sequence: 'MTEYK');
        $options = new RequestOptions(headers: ['Idempotency-Key' => 'idem_test']);
        [$guarantee, $evidence, $significance] = $this->searchControls();

        $single = $client->homology(
            database: 'tomato',
            evidence: $evidence,
            guarantee: $guarantee,
            limit: 25,
            query: $query,
            rafflesiaApiVersion: '2026-08-08',
            significance: $significance,
            options: $options,
        );
        self::assertSame('homology_candidate_set', $single->object);
        self::assertSame('request_id_01234', $single->requestId);
        self::assertSame('01234', $single->candidates[0]->target->id);
        $request = $this->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/homology', $request->getUri()->getPath());
        self::assertSame('idem_test', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('2026-08-08', $request->getHeaderLine('Rafflesia-API-Version'));
        self::assertSame(
            [
                'database' => 'tomato',
                'evidence' => 'pairwise_sequence_alignment',
                'guarantee' => 'approximate',
                'limit' => 25,
                'query' => ['sequence' => 'MTEYK', 'kind' => 'protein_sequence'],
                'significance' => 'if_available',
            ],
            json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );

        $multi = $client->homologyMany(
            database: 'tomato',
            evidence: $evidence,
            guarantee: $guarantee,
            limit: 25,
            queries: [$query],
            rafflesiaApiVersion: '2026-08-08',
            significance: $significance,
            options: $options,
        );
        self::assertSame('homology_search_result_set_batch', $multi->object);
        self::assertSame('homology_candidate_set', $multi->responses[0]->object);
        self::assertSame(1, $multi->responses[0]->queryIndex);
        self::assertSame('/v1/homology/batch', $this->getLastRequest()->getUri()->getPath());
        self::assertSame('2026-08-08', $this->getLastRequest()->getHeaderLine('Rafflesia-API-Version'));
    }

    public function testValidationHappensBeforeIo(): void
    {
        $client = $this->createMockClient([]);
        $query = new HomologyQuery(sequence: 'MTEYK');
        [$guarantee, $evidence, $significance] = $this->searchControls();

        try {
            $client->homology(
                database: 'tomato',
                databaseReleaseId: 'release_test',
                evidence: $evidence,
                guarantee: $guarantee,
                query: $query,
                significance: $significance,
            );
            self::fail('Expected selector validation to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('mutually exclusive', $exception->getMessage());
        }

        try {
            $client->homologyMany(
                database: 'tomato',
                evidence: $evidence,
                guarantee: $guarantee,
                queries: [],
                significance: $significance,
            );
            self::fail('Expected empty batch validation to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('between 1 and 16', $exception->getMessage());
        }

    }

    public function testCanonicalSemanticErrorIsExposed(): void
    {
        $client = $this->createMockClient([[
            'status' => 422,
            'body' => [
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'query_invalid',
                    'message' => 'query sequence is invalid',
                    'param' => 'query.sequence',
                    'request_id' => 'req_error',
                    'doc_url' => 'https://rafflesia.ai/docs/errors/query_invalid',
                    'details' => ['residue' => 'B'],
                ],
            ],
        ]]);
        [$guarantee, $evidence, $significance] = $this->searchControls();

        try {
            $client->homology(
                database: 'tomato',
                evidence: $evidence,
                guarantee: $guarantee,
                query: new HomologyQuery(sequence: 'MTEYK'),
                significance: $significance,
            );
            self::fail('Expected the API error.');
        } catch (RafflesiaException $error) {
            self::assertSame(422, $error->statusCode);
            self::assertSame('invalid_request_error', $error->errorType);
            self::assertSame('query_invalid', $error->errorCode);
            self::assertSame('query sequence is invalid', $error->getMessage());
            self::assertSame('query.sequence', $error->errorParam);
            self::assertSame('req_error', $error->requestId);
            self::assertSame('B', $error->details['residue']);
        }
    }

    /**
     * @return array{
     *   Guarantees,
     *   Evidence,
     *   Requirements
     * }
     */
    private function searchControls(): array
    {
        return [
            Guarantees::Approximate,
            Evidence::PairwiseSequenceAlignment,
            Requirements::IfAvailable,
        ];
    }
}
