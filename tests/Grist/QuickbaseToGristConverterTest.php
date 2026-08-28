<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests\Grist;

use PHPUnit\Framework\TestCase;
use Survos\Quickbase\Adapter\QuickbaseAdapter;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\Grist\ColumnPlan;
use Survos\QuickbaseBundle\Grist\ConversionPlan;
use Survos\QuickbaseBundle\Grist\QuickbaseToGristConverter;
use Survos\RecordStore\Model\ApplicationReference;

final class QuickbaseToGristConverterTest extends TestCase
{
    public function testReferenceFieldsResolveToTheGristTableTheyPointAt(): void
    {
        $plan = $this->plan();

        $reference = self::column($plan, 'Orders', 'Related Customer');
        self::assertSame('Ref:Customers', $reference->gristType);
        self::assertSame('Customers', $reference->referencedTable);
        self::assertSame('Ref:Customers', $reference->definition['type']);
    }

    /** A reference to a table left out of the conversion keeps the raw id rather than dropping it. */
    public function testOutOfScopeReferenceFallsBackToTheRawRecordId(): void
    {
        $plan = $this->plan(only: ['Orders']);

        $reference = self::column($plan, 'Orders', 'Related Customer');
        self::assertTrue($reference->converted);
        self::assertSame('Int', $reference->gristType);
        self::assertStringContainsString('not in this conversion', (string) $reference->note);
    }

    public function testMultipleChoiceCarriesItsAllowedValues(): void
    {
        $status = self::column($this->plan(), 'Orders', 'Status');

        self::assertSame('Choice', $status->gristType);
        self::assertSame(
            '{"choices":["Open","Shipped"],"choiceOptions":{}}',
            $status->definition['widgetOptions'],
        );
    }

    public function testMultitextBecomesAChoiceList(): void
    {
        self::assertSame('ChoiceList', self::column($this->plan(), 'Orders', 'Tags')->gristType);
    }

    public function testFormulaFieldsAreKeptAsStaticValuesAndSaySo(): void
    {
        $total = self::column($this->plan(), 'Orders', 'Total');

        self::assertTrue($total->converted);
        self::assertSame('Numeric', $total->gristType);
        self::assertStringContainsString('now a static value', (string) $total->note);
    }

    public function testTheQuickbaseRecordIdBecomesTheKeyColumn(): void
    {
        $key = self::column($this->plan(), 'Orders', 'Record ID#');

        self::assertSame(QuickbaseToGristConverter::KEY_COLUMN, $key->id);
        self::assertSame('Int', $key->gristType);
    }

    /** Nothing disappears: a field with no Grist equivalent is still in the plan, with a reason. */
    public function testFieldsWithNoEquivalentAreReportedRatherThanOmitted(): void
    {
        $plan = $this->plan();

        $user = self::column($plan, 'Orders', 'Owner');
        self::assertFalse($user->converted);
        self::assertNull($user->id);
        self::assertStringContainsString('no user column type', (string) $user->note);

        self::assertFalse(self::column($plan, 'Orders', 'Line Items')->converted);

        $labels = array_column($plan->losses(), 'field');
        self::assertContains('Owner', $labels);
        self::assertContains('Line Items', $labels);
    }

    public function testLabelsBecomeGristIdentifiers(): void
    {
        self::assertSame('Num_of_Line_Items', self::column($this->plan(), 'Orders', '# of Line Items')->id);
    }

    public function testSkipDerivedDropsLookupAndSummaryFields(): void
    {
        $count = self::column($this->plan(skipDerived: true), 'Orders', '# of Line Items');

        self::assertFalse($count->converted);
        self::assertStringContainsString('skipped by request', (string) $count->note);
    }

    /** @param list<string> $only */
    private function plan(array $only = [], bool $skipDerived = false): ConversionPlan
    {
        $client = $this->createStub(QuickbaseClientInterface::class);
        $client->method('tables')->willReturn([
            ['id' => 'tbl-cust', 'name' => 'Customers'],
            ['id' => 'tbl-ord', 'name' => 'Orders'],
        ]);
        $client->method('fields')->willReturnCallback(static fn (string $table): array => match ($table) {
            'tbl-cust' => [
                ['id' => 3, 'label' => 'Record ID#', 'fieldType' => 'recordid', 'mode' => ''],
                ['id' => 6, 'label' => 'Name', 'fieldType' => 'text', 'mode' => ''],
            ],
            'tbl-ord' => [
                ['id' => 3, 'label' => 'Record ID#', 'fieldType' => 'recordid', 'mode' => ''],
                // Quickbase reports a reference as numeric; only properties say otherwise.
                ['id' => 6, 'label' => 'Related Customer', 'fieldType' => 'numeric', 'mode' => '', 'properties' => ['foreignKey' => true, 'masterTableId' => 'tbl-cust']],
                ['id' => 7, 'label' => 'Status', 'fieldType' => 'text-multiple-choice', 'mode' => '', 'properties' => ['choices' => ['Open', 'Shipped']]],
                ['id' => 8, 'label' => 'Tags', 'fieldType' => 'multitext', 'mode' => '', 'properties' => ['choices' => ['a', 'b']]],
                ['id' => 9, 'label' => 'Total', 'fieldType' => 'currency', 'mode' => 'formula', 'properties' => ['formula' => '[Qty] * [Price]']],
                ['id' => 10, 'label' => 'Owner', 'fieldType' => 'user', 'mode' => ''],
                ['id' => 11, 'label' => 'Line Items', 'fieldType' => 'dblink', 'mode' => ''],
                ['id' => 12, 'label' => '# of Line Items', 'fieldType' => 'numeric', 'mode' => 'summary', 'properties' => ['summaryFunction' => 'COUNT']],
            ],
            default => [],
        });

        $schema = (new QuickbaseAdapter($client))->schema(new ApplicationReference('shop', 'quickbase', 'app-1'));

        return (new QuickbaseToGristConverter($client))->plan($schema, $only, $skipDerived);
    }

    private static function column(ConversionPlan $plan, string $table, string $label): ColumnPlan
    {
        foreach ($plan->tables as $candidate) {
            if ($candidate->label !== $table) {
                continue;
            }
            foreach ($candidate->columns as $column) {
                if ($column->label === $label) {
                    return $column;
                }
            }
        }

        self::fail(sprintf('No plan for %s.%s', $table, $label));
    }
}
