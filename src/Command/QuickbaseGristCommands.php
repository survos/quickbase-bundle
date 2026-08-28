<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Command;

use Survos\Grist\Adapter\GristAdapterFactory;
use Survos\Grist\Service\GristDocument;
use Survos\Quickbase\Adapter\QuickbaseAdapter;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\Quickbase\QuickbaseAppRegistry;
use Survos\QuickbaseBundle\Grist\ColumnPlan;
use Survos\QuickbaseBundle\Grist\ConversionPlan;
use Survos\QuickbaseBundle\Grist\QuickbaseGristImporter;
use Survos\QuickbaseBundle\Grist\QuickbaseToGristConverter;
use Survos\QuickbaseBundle\Grist\UnsupportedFeature;
use Survos\RecordStore\Model\ApplicationReference;
use Survos\RecordStore\Registry\RecordStoreRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One-directional: read a Quickbase application, produce the equivalent Grist document.
 *
 * Lives in quickbase-bundle rather than record-store-bundle because the Quickbase client is
 * configured here -- realm, token and the app registry -- and record-store-bundle is
 * deliberately provider-agnostic, as survos/grist-bundle says in as many words where it
 * registers the Grist adapter. The Grist half needs no bundle: a base_uri and a token off the
 * record-store connection are enough to build a client from the grist-php library directly.
 */
final readonly class QuickbaseGristCommands
{
    public function __construct(
        private QuickbaseClientInterface $quickbase,
        private QuickbaseAppRegistry $apps,
        private RecordStoreRegistry $stores,
        // The factory is built here rather than injected: it is a plain class from the
        // grist-php *library*, and survos/grist-bundle registers it as a service with the
        // record-store adapter-factory tag. Registering it here too would be a load-order
        // race for the same service id, and the loser drops the tag -- which does not fail,
        // it just quietly removes Grist from the record-store registry.
        private HttpClientInterface $http,
    ) {
    }

    #[AsCommand('quickbase:to-grist', 'Create the Grist equivalent of a Quickbase application')]
    public function toGrist(
        SymfonyStyle $io,
        #[Argument('Configured Quickbase app alias or app id')] string $app,
        #[Option('Existing Grist document id; omit to create a new document')] ?string $doc = null,
        #[Option('Record-store connection with driver "grist"')] ?string $connection = null,
        #[Option('Grist workspace id to create the new document in')] ?string $workspace = null,
        #[Option('Name for the new document; defaults to the Quickbase app name')] ?string $name = null,
        #[Option('Print the intended schema and type decisions, write nothing')] bool $dryRun = false,
        #[Option('Copy the rows as well as the schema')] bool $withData = false,
        #[Option('Comma-separated Quickbase table names or ids; default is all')] string $tables = '',
        #[Option('Drop Quickbase lookup and summary fields instead of freezing their value')] bool $skipDerived = false,
        #[Option('Timezone for DateTime columns')] string $timezone = 'UTC',
        #[Option('Maximum bytes per Grist write; Grist 413s on payload size, not row count')] int $batchBytes = GristDocument::DEFAULT_MAX_BYTES,
        #[Option('Emit the plan as JSON')] bool $json = false,
    ): int {
        $appId = $this->apps->resolve($app);
        $converter = new QuickbaseToGristConverter($this->quickbase);

        // --json has to be the only thing on stdout, or the plan cannot be piped anywhere.
        $say = $json ? static fn (string $m): null => null : $io->comment(...);

        $json or $io->title(sprintf('Quickbase %s (%s) to Grist', $app, $appId));
        $say('Reading the Quickbase schema...');

        $schema = (new QuickbaseAdapter($this->quickbase))->schema(
            new ApplicationReference($app, 'quickbase', $appId),
        );
        $plan = $converter->plan($schema, self::csv($tables), $skipDerived, $timezone);

        $say('Reading reports, forms and pipelines...');
        $plan = $plan->withUnsupported($converter->unsupportedFeatures($appId, $plan->tables));

        if ($json) {
            $io->writeln(self::encode($plan));
        } else {
            $this->report($io, $plan);
        }

        if ($dryRun) {
            $json or $io->note('Dry run: nothing was written. Re-run without --dry-run to create the document.');

            return Command::SUCCESS;
        }

        $document = $this->document($io, $doc, $connection, $workspace, $name ?? $plan->sourceAppName);
        $importer = new QuickbaseGristImporter($this->quickbase);

        $created = $importer->createSchema($plan, $document);
        $io->success(sprintf(
            'Document %s: %d table%s created, %d existing table%s extended.',
            $document->id,
            count($created['tables']),
            1 === count($created['tables']) ? '' : 's',
            count($created['columns']),
            1 === count($created['columns']) ? '' : 's',
        ));

        if (!$withData) {
            $io->note('Schema only. Re-run with --with-data to copy the rows; it is resumable, so a failed run is resumed by running it again.');

            return Command::SUCCESS;
        }

        return $this->import($io, $plan, $document, $importer, $batchBytes);
    }

    private function import(
        SymfonyStyle $io,
        ConversionPlan $plan,
        GristDocument $document,
        QuickbaseGristImporter $importer,
        int $batchBytes,
    ): int {
        $rows = [];

        $io->section('Values');
        foreach ($plan->tables as $table) {
            $result = $importer->importValues($plan, $table, $document, $batchBytes);
            $rows[] = [$table->label, $result['records'], $result['batches']];
            $io->writeln(sprintf('  %s: %d rows in %d batches', $table->label, $result['records'], $result['batches']));
        }

        // References only after every table has its rows: a row id cannot be resolved before
        // the row it points at exists.
        $io->section('References');
        $unresolved = 0;
        foreach ($plan->tables as $table) {
            if ([] === $table->references()) {
                continue;
            }
            $result = $importer->importReferences($plan, $table, $document, $batchBytes);
            $unresolved += $result['unresolved'];
            $io->writeln(sprintf(
                '  %s: %d rows linked%s',
                $table->label,
                $result['records'],
                $result['unresolved'] > 0 ? sprintf(', %d unresolved', $result['unresolved']) : '',
            ));
        }

        $io->section('Attachments');
        $failed = $files = 0;
        foreach ($plan->tables as $table) {
            if ([] === $table->attachments()) {
                continue;
            }
            $result = $importer->importAttachments($plan, $table, $document, $batchBytes);
            $files += $result['files'];
            $failed += $result['failed'];
            $io->writeln(sprintf('  %s: %d files%s', $table->label, $result['files'], $result['failed'] > 0 ? sprintf(', %d failed', $result['failed']) : ''));
        }
        if (0 === $files) {
            $io->writeln('  (none)');
        }

        $io->table(['Table', 'Rows', 'Batches'], $rows);

        if ($unresolved > 0) {
            $io->warning(sprintf(
                '%d reference%s could not be resolved: the parent row is not in the document, because it was '
                .'deleted in Quickbase or its table was excluded from this conversion.',
                $unresolved,
                1 === $unresolved ? '' : 's',
            ));
        }
        if ($failed > 0) {
            $io->warning(sprintf('%d attachment%s failed to transfer. Re-running picks them up again.', $failed, 1 === $failed ? '' : 's'));
        }

        $io->success('Import complete. Every pass upserts on '.$plan->keyColumn.', so re-running is an update, not a duplicate.');

        return Command::SUCCESS;
    }

    /** The report a human reads before trusting the conversion. */
    private function report(SymfonyStyle $io, ConversionPlan $plan): void
    {
        foreach ($plan->tables as $table) {
            $io->section(sprintf('%s  ->  %s', $table->label, $table->id));
            $io->table(
                ['Quickbase field', 'Quickbase type', 'Grist column', 'Grist type'],
                array_map(static fn (ColumnPlan $c): array => [
                    $c->label,
                    $c->source(),
                    $c->id ?? '—',
                    $c->gristType ?? '(not converted)',
                ], $table->columns),
            );
        }

        $losses = $plan->losses();
        $io->section('Lossy conversions');
        if ([] === $losses) {
            $io->writeln('None.');
        } else {
            foreach ($losses as $loss) {
                $io->writeln(sprintf(
                    ' <comment>%s.%s</comment> (%s -> %s)',
                    $loss['table'],
                    $loss['field'],
                    $loss['source'],
                    $loss['target'],
                ));
                $io->writeln('   '.wordwrap($loss['note'], 100, "\n   "));
            }
        }

        $io->section('Not converted: application features');
        // Grouped by the advice, not just the verdict: a chart report and a summary report are
        // both "rebuild in Grist" and rebuilt differently, so one note per verdict would
        // attach the wrong instruction to most of the list.
        $grouped = [];
        foreach ($plan->unsupported as $feature) {
            $grouped[$feature->verdict->value."\0".$feature->note][] = $feature;
        }
        foreach ($grouped as $features) {
            $io->writeln(sprintf(' <info>%s</info>', strtoupper($features[0]->verdict->label())));
            $io->writeln('   '.wordwrap($features[0]->note, 100, "\n   "));
            foreach ($features as $feature) {
                $io->writeln(sprintf('     %s · %s (%s)', $feature->scope, $feature->name, $feature->kind));
            }
            $io->newLine();
        }

        $converted = array_sum(array_map(static fn ($t): int => count($t->converted()), $plan->tables));
        $dropped = array_sum(array_map(static fn ($t): int => count($t->dropped()), $plan->tables));
        $io->definitionList(
            ['Tables' => (string) count($plan->tables)],
            ['Columns created' => (string) $converted],
            ['Fields not converted' => (string) $dropped],
            ['Lossy conversions' => (string) count($losses)],
            ['Application features not converted' => (string) count($plan->unsupported)],
        );
    }

    private function document(
        SymfonyStyle $io,
        ?string $doc,
        ?string $connection,
        ?string $workspace,
        string $name,
    ): GristDocument {
        $client = (new GristAdapterFactory($this->http))
            ->client($this->stores->connectionConfiguration($connection ?? $this->gristConnection()));

        if (null !== $doc) {
            return new GristDocument($client, $doc);
        }

        if (null === $workspace) {
            throw new \InvalidArgumentException(
                'Creating a document needs --workspace=<id>. List them with: '
                .'GET {grist_host}/api/orgs/{org}/workspaces',
            );
        }

        $document = GristDocument::create($client, $workspace, $name);
        $io->success(sprintf('Created Grist document %s ("%s") in workspace %s.', $document->id, $name, $workspace));
        $io->note('Grist seeds a new document with an empty "Table1"; it is left alone here. Remove it in the UI if you do not want it.');

        return $document;
    }

    /** The single configured Grist connection, when there is exactly one to be unambiguous about. */
    private function gristConnection(): string
    {
        $grist = [];
        foreach ($this->stores->applicationNames() as $application) {
            $reference = $this->stores->application($application);
            if ('grist' === $this->stores->adapterFor($reference)->provider()) {
                $grist[$reference->connection] = true;
            }
        }
        $names = array_keys($grist);

        return match (count($names)) {
            1 => $names[0],
            0 => throw new \InvalidArgumentException('No record-store connection uses the "grist" driver; configure one, or pass --connection.'),
            default => throw new \InvalidArgumentException(sprintf('Several Grist connections are configured (%s); pass --connection.', implode(', ', $names))),
        };
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return '' === trim($value)
            ? []
            : array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $i): bool => '' !== $i));
    }

    private static function encode(ConversionPlan $plan): string
    {
        return json_encode([
            'sourceAppId' => $plan->sourceAppId,
            'sourceAppName' => $plan->sourceAppName,
            'keyColumn' => $plan->keyColumn,
            'tables' => array_map(static fn ($t): array => [
                'sourceId' => $t->sourceId,
                'id' => $t->id,
                'label' => $t->label,
                'columns' => array_map(static fn (ColumnPlan $c): array => [
                    'fieldId' => $c->fieldId,
                    'label' => $c->label,
                    'quickbaseType' => $c->source(),
                    'id' => $c->id,
                    'gristType' => $c->gristType,
                    'definition' => $c->definition,
                    'converted' => $c->converted,
                    'note' => $c->note,
                ], $t->columns),
            ], $plan->tables),
            'losses' => $plan->losses(),
            'unsupported' => array_map(static fn (UnsupportedFeature $f): array => [
                'scope' => $f->scope,
                'kind' => $f->kind,
                'name' => $f->name,
                'verdict' => $f->verdict->value,
                'note' => $f->note,
            ], $plan->unsupported),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
