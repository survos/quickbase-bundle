<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Survos\QuickbaseBundle\Adapter\QuickbaseAdapter;
use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\RecordStoreBundle\Exception\UnsupportedRecordStoreOperation;
use Survos\RecordStoreBundle\Model\ApplicationReference;
use Survos\RecordStoreBundle\Model\FieldType;
use Survos\RecordStoreBundle\Model\Record;
use Survos\RecordStoreBundle\Model\RecordQuery;
use Survos\RecordStoreBundle\Model\RecordSort;
use Survos\RecordStoreBundle\Model\SortDirection;
use Survos\RecordStoreBundle\Model\TableReference;
use Survos\RecordStoreBundle\Model\UpsertRequest;

final class QuickbaseAdapterTest extends TestCase
{
    public function testNormalizesSchemaAndLogicalRecordFields(): void
    {
        $client = $this->createMock(QuickbaseClientInterface::class);
        $client->expects($this->once())->method('tables')->with('app-1')->willReturn([
            ['id' => 'table-1', 'name' => 'People'],
        ]);
        $client->expects($this->once())->method('fields')->with('table-1')->willReturn([
            ['id' => 3, 'label' => 'Record ID#', 'fieldType' => 'recordid'],
            ['id' => 6, 'label' => 'Full name', 'fieldType' => 'text'],
        ]);
        $client->expects($this->once())->method('queryRecords')->with(
            'table-1',
            [6],
            "{'7'.EX.'Active'}",
            [['fieldId' => 6, 'order' => 'DESC']],
            0,
            10,
        )->willReturn([
            'data' => [['3' => ['value' => 7], '6' => ['value' => 'Ada'], '7' => ['value' => 'Active']]],
            'metadata' => ['totalRecords' => 1],
        ]);
        $adapter = new QuickbaseAdapter($client);
        $table = self::table();
        $application = new ApplicationReference('contacts', 'quickbase', 'app-1', ['people' => $table]);

        $schema = $adapter->schema($application);
        self::assertSame(FieldType::Integer, $schema->tables[0]->fields[0]->type);
        self::assertSame('name', $schema->tables[0]->fields[1]->name);

        $page = $adapter->query($table, new RecordQuery(
            ['name'],
            ['status' => ['Active']],
            [new RecordSort('name', SortDirection::Descending)],
            10,
        ));
        self::assertSame(7, $page->records[0]->id);
        self::assertSame('Ada', $page->records[0]->fields['name']);
        self::assertSame(1, $page->total);
    }

    public function testMapsLogicalFieldsForUpsert(): void
    {
        $client = $this->createMock(QuickbaseClientInterface::class);
        $client->expects($this->once())->method('upsertRecords')->with(
            'table-1',
            [[6 => 'Ada', 8 => 'ada@example.test']],
            8,
            [3],
        )->willReturn(['metadata' => ['createdRecordIds' => [7]]]);
        $adapter = new QuickbaseAdapter($client);

        $result = $adapter->upsert(self::table(), new UpsertRequest([
            new Record(['name' => 'Ada', 'email' => 'ada@example.test']),
        ], ['email']));

        self::assertSame([7], $result->createdIds);
    }

    public function testRejectsCompoundUpsertKeys(): void
    {
        $adapter = new QuickbaseAdapter($this->createStub(QuickbaseClientInterface::class));

        $this->expectException(UnsupportedRecordStoreOperation::class);
        $adapter->upsert(self::table(), new UpsertRequest([new Record(['name' => 'Ada'])], ['name', 'email']));
    }

    private static function table(): TableReference
    {
        return new TableReference('contacts', 'app-1', 'quickbase', 'people', 'table-1', [
            'record_id' => 3,
            'name' => 6,
            'status' => 7,
            'email' => 8,
        ]);
    }
}
