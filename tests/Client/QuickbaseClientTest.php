<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests\Client;

use PHPUnit\Framework\TestCase;
use Survos\QuickbaseBundle\Client\QuickbaseClient;
use Survos\QuickbaseBundle\Exception\QuickbaseApiException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QuickbaseClientTest extends TestCase
{
    public function testUpsertNormalizesFieldValues(): void
    {
        $response = new MockResponse('{"metadata":{"createdRecordIds":[42]}}', ['http_code' => 200]);
        $client = new QuickbaseClient(new MockHttpClient($response, 'https://api.quickbase.test/v1/'));

        $result = $client->upsertRecords(
            'table-id',
            [[6 => 'SKU-123', 7 => ['value' => 19.95]]],
            mergeFieldId: 6,
            fieldsToReturn: [3, 6, 7],
        );

        $metadata = $result['metadata'];
        self::assertIsArray($metadata);
        self::assertSame([42], $metadata['createdRecordIds']);
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://api.quickbase.test/v1/records', $response->getRequestUrl());
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        self::assertJsonStringEqualsJsonString(
            '{"to":"table-id","data":[{"6":{"value":"SKU-123"},"7":{"value":19.95}}],"mergeFieldId":6,"fieldsToReturn":[3,6,7]}',
            $body,
        );
    }

    public function testApiFailureIncludesRayAndDecodedResponse(): void
    {
        $client = new QuickbaseClient(new MockHttpClient(new MockResponse(
            '{"message":"Invalid input"}',
            ['http_code' => 400, 'response_headers' => ['qb-api-ray: ray-123']],
        )));

        try {
            $client->request('GET', 'fields');
            self::fail('Expected a QuickbaseApiException.');
        } catch (QuickbaseApiException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('ray-123', $exception->apiRay);
            self::assertSame(['message' => 'Invalid input'], $exception->response);
        }
    }
}
