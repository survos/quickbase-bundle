<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Client;

use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\Exception\QuickbaseApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class QuickbaseClient implements QuickbaseClientInterface
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    public function tables(string $appId): array
    {
        if ('' === trim($appId)) {
            throw new \InvalidArgumentException('The Quickbase app ID cannot be empty.');
        }

        return self::objectList($this->request('GET', 'tables', [
            'query' => ['appId' => $appId],
        ]));
    }

    public function fields(string $tableId, bool $includeFieldPermissions = false): array
    {
        if ('' === trim($tableId)) {
            throw new \InvalidArgumentException('The Quickbase table ID cannot be empty.');
        }

        $query = ['tableId' => $tableId];
        if ($includeFieldPermissions) {
            // Quickbase documents this query value as the literal string "true";
            // HttpClient's default boolean encoding (1/0) is rejected with HTTP 400.
            $query['includeFieldPerms'] = 'true';
        }

        return self::objectList($this->request('GET', 'fields', ['query' => $query]));
    }

    public function upsertRecords(string $tableId, iterable $records, ?int $mergeFieldId = null, array $fieldsToReturn = []): array
    {
        if ('' === trim($tableId)) {
            throw new \InvalidArgumentException('The Quickbase table ID cannot be empty.');
        }

        $data = [];
        foreach ($records as $record) {
            $data[] = self::normalizeRecord($record);
        }
        if ([] === $data) {
            throw new \InvalidArgumentException('At least one Quickbase record is required.');
        }

        $payload = ['to' => $tableId, 'data' => $data];
        if (null !== $mergeFieldId) {
            $payload['mergeFieldId'] = $mergeFieldId;
        }
        if ([] !== $fieldsToReturn) {
            $payload['fieldsToReturn'] = $fieldsToReturn;
        }

        return self::object($this->request('POST', 'records', ['json' => $payload]));
    }

    /** @return array<array-key, mixed> */
    public function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new QuickbaseApiException('Quickbase transport failure: '.$exception->getMessage(), 0, previous: $exception);
        }

        $decoded = [];
        if ('' !== $content) {
            try {
                $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($value)) {
                    $decoded = ['data' => $value];
                } else {
                    $decoded = $value;
                }
            } catch (\JsonException $exception) {
                throw new QuickbaseApiException(
                    sprintf('Quickbase returned invalid JSON (HTTP %d).', $statusCode),
                    $statusCode,
                    self::firstHeader($headers, 'qb-api-ray'),
                    previous: $exception,
                );
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['message'] ?? $decoded['description'] ?? sprintf('Quickbase request failed with HTTP %d.', $statusCode);
            throw new QuickbaseApiException(
                is_string($message) ? $message : sprintf('Quickbase request failed with HTTP %d.', $statusCode),
                $statusCode,
                self::firstHeader($headers, 'qb-api-ray'),
                $decoded,
            );
        }

        return $decoded;
    }

    /**
     * @param array<int|string, mixed> $record
     *
     * @return array<int, mixed>
     */
    private static function normalizeRecord(array $record): array
    {
        $normalized = [];
        foreach ($record as $fieldId => $value) {
            if ((!is_int($fieldId) && !ctype_digit((string) $fieldId)) || (int) $fieldId < 1) {
                throw new \InvalidArgumentException(sprintf('Quickbase field ID "%s" must be numeric.', $fieldId));
            }
            $normalized[(int) $fieldId] = is_array($value) && array_key_exists('value', $value) ? $value : ['value' => $value];
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private static function objectList(array $value): array
    {
        $objects = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException('Quickbase returned a non-object item in a metadata list.');
            }

            $object = [];
            foreach ($item as $key => $fieldValue) {
                if (!is_string($key)) {
                    throw new \UnexpectedValueException('Quickbase returned a metadata object with a non-string key.');
                }
                $object[$key] = $fieldValue;
            }
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $object = [];
        foreach ($value as $key => $fieldValue) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Quickbase returned an object with a non-string key.');
            }
            $object[$key] = $fieldValue;
        }

        return $object;
    }

    /** @param array<string, list<string>> $headers */
    private static function firstHeader(array $headers, string $name): ?string
    {
        return ($headers[strtolower($name)] ?? [])[0] ?? null;
    }
}
