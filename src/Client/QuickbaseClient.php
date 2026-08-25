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

        return $this->request('POST', 'records', ['json' => $payload]);
    }

    /** @return array<string, mixed> */
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
                    foreach ($value as $key => $item) {
                        if (!is_string($key)) {
                            throw new \JsonException('Expected a JSON object from Quickbase.');
                        }
                        $decoded[$key] = $item;
                    }
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

    /** @param array<string, list<string>> $headers */
    private static function firstHeader(array $headers, string $name): ?string
    {
        return ($headers[strtolower($name)] ?? [])[0] ?? null;
    }
}
