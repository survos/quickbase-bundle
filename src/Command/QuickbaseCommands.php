<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Command;

use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\Exception\QuickbaseApiException;
use Survos\QuickbaseBundle\QuickbaseAppRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class QuickbaseCommands
{
    public function __construct(
        private QuickbaseClientInterface $quickbase,
        private QuickbaseAppRegistry $apps,
    ) {
    }

    #[AsCommand('quickbase:apps', 'List configured Quickbase apps')]
    public function apps(SymfonyStyle $io, #[Option('Emit the configured apps as JSON')] bool $json = false): int
    {
        $apps = $this->apps->all();

        if ($json) {
            $io->writeln(json_encode($apps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($apps as $name => $app) {
            $rows[] = [$name, $app['id']];
        }

        $io->title('Configured Quickbase apps');
        $io->table(['Name', 'App ID'], $rows);
        $io->success(sprintf('%d app%s.', count($apps), 1 === count($apps) ? '' : 's'));

        return Command::SUCCESS;
    }

    #[AsCommand('quickbase:tables', 'List the tables in a Quickbase app')]
    public function tables(
        SymfonyStyle $io,
        #[Argument('Configured app name or Quickbase application ID')] string $app,
        #[Option('Emit the unmodified API response as JSON')] bool $json = false,
    ): int {
        $appId = $this->apps->resolve($app);

        try {
            $tables = $this->quickbase->tables($appId);
        } catch (QuickbaseApiException $exception) {
            return $this->renderApiError($io, $exception);
        }

        if ($json) {
            $io->writeln(json_encode($tables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = array_map(static fn (array $table): array => [
            self::scalar($table['id'] ?? null),
            self::scalar($table['name'] ?? null),
            self::scalar($table['singleRecordName'] ?? null),
            self::scalar($table['description'] ?? null),
        ], $tables);

        $label = $app === $appId ? $appId : sprintf('%s (%s)', $app, $appId);
        $io->title(sprintf('Quickbase tables · %s', $label));
        $io->table(['ID', 'Name', 'Record name', 'Description'], $rows);
        $io->success(sprintf('%d table%s.', count($tables), 1 === count($tables) ? '' : 's'));

        return Command::SUCCESS;
    }

    #[AsCommand('quickbase:fields', 'List the fields in a Quickbase table')]
    public function fields(
        SymfonyStyle $io,
        #[Argument('Quickbase table ID')] string $tableId,
        #[Option('Include field permission metadata')] bool $permissions = false,
        #[Option('Emit the unmodified API response as JSON')] bool $json = false,
    ): int {
        try {
            $fields = $this->quickbase->fields($tableId, $permissions);
        } catch (QuickbaseApiException $exception) {
            return $this->renderApiError($io, $exception);
        }

        if ($json) {
            $io->writeln(json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = array_map(static fn (array $field): array => [
            self::scalar($field['id'] ?? null),
            self::scalar($field['label'] ?? null),
            self::scalar($field['fieldType'] ?? null),
            self::scalar($field['mode'] ?? null),
        ], $fields);

        $io->title(sprintf('Quickbase fields · %s', $tableId));
        $io->table(['ID', 'Label', 'Type', 'Mode'], $rows);
        $io->success(sprintf('%d field%s.', count($fields), 1 === count($fields) ? '' : 's'));

        return Command::SUCCESS;
    }

    private function renderApiError(SymfonyStyle $io, QuickbaseApiException $exception): int
    {
        $io->error($exception->getMessage());
        if (null !== $exception->apiRay) {
            $io->note('Quickbase API ray: '.$exception->apiRay);
        }

        return Command::FAILURE;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) || null === $value ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
