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
    public function testCreatesUpdatesAndDeletesAnApp(): void
    {
        $responses = [
            new MockResponse('{"id":"bapp1","name":"Lions"}'),
            new MockResponse('{"id":"bapp1","name":"Lions Service"}'),
            new MockResponse('{"deletedAppId":"bapp1"}'),
        ];
        $client = new QuickbaseClient(new MockHttpClient($responses, 'https://api.quickbase.test/v1/'));

        self::assertSame('bapp1', $client->createApp(['name' => 'Lions'])['id']);
        self::assertSame('Lions Service', $client->updateApp('bapp1', ['name' => 'Lions Service'])['name']);
        self::assertSame('bapp1', $client->deleteApp('bapp1', 'Lions Service')['deletedAppId']);

        self::assertSame('POST', $responses[0]->getRequestMethod());
        self::assertSame('POST', $responses[1]->getRequestMethod());
        self::assertSame('DELETE', $responses[2]->getRequestMethod());
        self::assertStringContainsString('"name":"Lions Service"', $responses[2]->getRequestOptions()['body']);
    }

    public function testGetsAppMetadata(): void
    {
        $response = new MockResponse('{"id":"bapp1","name":"Lions"}');
        $client = new QuickbaseClient(new MockHttpClient($response, 'https://api.quickbase.test/v1/'));

        self::assertSame('Lions', $client->app('bapp1')['name']);
        self::assertSame('https://api.quickbase.test/v1/apps/bapp1', $response->getRequestUrl());
    }

    public function testListsTablesForAnApp(): void
    {
        $response = new MockResponse('[{"id":"btable1","name":"Items"}]');
        $client = new QuickbaseClient(new MockHttpClient($response, 'https://api.quickbase.test/v1/'));

        $tables = $client->tables('bapp1');

        self::assertSame([['id' => 'btable1', 'name' => 'Items']], $tables);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://api.quickbase.test/v1/tables?appId=bapp1', $response->getRequestUrl());
    }

    public function testListsFieldsForATableWithPermissions(): void
    {
        $response = new MockResponse('[{"id":6,"label":"SKU","fieldType":"text"}]');
        $client = new QuickbaseClient(new MockHttpClient($response, 'https://api.quickbase.test/v1/'));

        $fields = $client->fields('btable1', true);

        self::assertSame([['id' => 6, 'label' => 'SKU', 'fieldType' => 'text']], $fields);
        self::assertSame('https://api.quickbase.test/v1/fields?tableId=btable1&includeFieldPerms=true', $response->getRequestUrl());
    }

    public function testListsRelationshipsForATable(): void
    {
        $response = new MockResponse('{"metadata":{"numRelationships":1,"skip":10,"totalRelationships":1},"relationships":[{"id":1,"parentTableId":"bparent"}]}');
        $client = new QuickbaseClient(new MockHttpClient($response, 'https://api.quickbase.test/v1/'));

        $relationships = $client->relationships('bchild', 10);

        self::assertSame([
            'metadata' => ['numRelationships' => 1, 'skip' => 10, 'totalRelationships' => 1],
            'relationships' => [['id' => 1, 'parentTableId' => 'bparent']],
        ], $relationships);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://api.quickbase.test/v1/tables/bchild/relationships?skip=10', $response->getRequestUrl());
    }

    public function testListsReportsForATable(): void
    {
        $response = new MockResponse('[{"id":"1","name":"All equipment","type":"table"}]');
        $client = new QuickbaseClient(new MockHttpClient($response, 'https://api.quickbase.test/v1/'));

        $reports = $client->reports('btable1');

        self::assertSame('All equipment', $reports[0]['name']);
        self::assertSame('https://api.quickbase.test/v1/reports?tableId=btable1', $response->getRequestUrl());
    }

    public function testExportsAndUpdatesQblSolutions(): void
    {
        $responses = [
            new MockResponse("Version: 0.14\nResources: {}\n", ['response_headers' => ['content-type: application/yaml']]),
            new MockResponse('{"solutionId":"solution-1"}'),
            new MockResponse('{"changes":[]}'),
        ];
        $client = new QuickbaseClient(new MockHttpClient($responses, 'https://api.quickbase.test/v1/'));

        $qbl = $client->exportSolution('solution-1');
        self::assertStringStartsWith('Version: 0.14', $qbl);
        self::assertSame('solution-1', $client->updateSolution('solution-1', $qbl)['solutionId']);
        self::assertSame([], $client->solutionChanges('solution-1', $qbl)['changes']);
        self::assertStringContainsString('QBL-Version: 0.14', implode("\n", $responses[0]->getRequestOptions()['headers']));
        self::assertStringContainsString('Version: 0.14', $responses[1]->getRequestOptions()['body']);
    }

    public function testPreservesUsefulNonJsonErrorText(): void
    {
        $client = new QuickbaseClient(new MockHttpClient(
            new MockResponse('<html><body>Forbidden by realm policy</body></html>', ['http_code' => 403]),
            'https://api.quickbase.test/v1/',
        ));

        $this->expectExceptionMessage('HTTP 403: Forbidden by realm policy');
        $client->app('bapp1');
    }

    public function testQueriesRecordsWithPaginationAndSorting(): void
    {
        $response = new MockResponse('{"data":[{"3":{"value":42},"6":{"value":"SKU-42"}}],"metadata":{"skip":5,"numRecords":1}}');
        $client = new QuickbaseClient(new MockHttpClient($response, 'https://api.quickbase.test/v1/'));

        $result = $client->queryRecords(
            'btable1',
            [3, 6],
            "{'6'.CT.'SKU'}",
            [['fieldId' => 3, 'order' => 'DESC']],
            5,
            25,
        );

        $data = $result['data'];
        self::assertIsArray($data);
        $record = $data[0];
        self::assertIsArray($record);
        $field = $record[6];
        self::assertIsArray($field);
        self::assertSame('SKU-42', $field['value']);
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://api.quickbase.test/v1/records/query', $response->getRequestUrl());
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        self::assertJsonStringEqualsJsonString(
            '{"from":"btable1","select":[3,6],"where":"{\'6\'.CT.\'SKU\'}","sortBy":[{"fieldId":3,"order":"DESC"}],"options":{"skip":5,"top":25}}',
            $body,
        );
    }

    public function testCreatesSchemaResources(): void
    {
        $responses = [
            new MockResponse('{"id":"btable1","name":"Equipment"}'),
            new MockResponse('{"id":6,"label":"Asset Tag"}'),
            new MockResponse('{"id":1,"parentTableId":"bparent"}'),
        ];
        $client = new QuickbaseClient(new MockHttpClient($responses, 'https://api.quickbase.test/v1/'));

        self::assertSame('btable1', $client->createTable('bapp1', [
            'name' => 'Equipment',
            'singleRecordName' => 'Equipment Item',
        ])['id']);
        self::assertSame(6, $client->createField('btable1', [
            'label' => 'Asset Tag',
            'fieldType' => 'text',
            'properties' => ['unique' => true],
        ])['id']);
        self::assertSame(1, $client->createRelationship('bchild', [
            'parentTableId' => 'bparent',
            'foreignKeyField' => ['label' => 'Related Equipment'],
        ])['id']);

        self::assertSame('https://api.quickbase.test/v1/tables?appId=bapp1', $responses[0]->getRequestUrl());
        self::assertSame('https://api.quickbase.test/v1/fields?tableId=btable1', $responses[1]->getRequestUrl());
        self::assertSame('https://api.quickbase.test/v1/tables/bchild/relationship', $responses[2]->getRequestUrl());
    }

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
