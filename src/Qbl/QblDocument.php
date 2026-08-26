<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Qbl;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/** PHP representation of a QBL document that preserves unknown future resource types. */
final readonly class QblDocument
{
    /** @param array<string, mixed> $document */
    public function __construct(private array $document)
    {
        self::validate($document);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        return new self($document);
    }

    public static function fromYaml(string $yaml): self
    {
        $document = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
        if (!is_array($document)) {
            throw new \InvalidArgumentException('A QBL document must be a YAML map.');
        }

        return new self($document);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    public function toYaml(): string
    {
        return Yaml::dump(self::tagReferences($this->document), 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)."\n";
    }

    /** @param array<string, mixed> $document */
    private static function validate(array $document): void
    {
        $version = $document['Version'] ?? null;
        if (!is_string($version) && !is_float($version) && !is_int($version)) {
            throw new \InvalidArgumentException('QBL requires a Version value.');
        }
        if (!is_array($document['Resources'] ?? null) || [] === $document['Resources']) {
            throw new \InvalidArgumentException('QBL requires a non-empty Resources map.');
        }
        foreach ($document['Resources'] as $logicalId => $resource) {
            if (!is_string($logicalId) || '' === trim($logicalId) || !is_array($resource)) {
                throw new \InvalidArgumentException('Every QBL resource must have a non-empty logical ID and map definition.');
            }
            if (!is_string($resource['Type'] ?? null) || !str_starts_with($resource['Type'], 'QB::')) {
                throw new \InvalidArgumentException(sprintf('QBL resource "%s" requires a namespaced QB:: Type.', $logicalId));
            }
        }
    }

    private static function tagReferences(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (1 === count($value)) {
            foreach (['!Ref' => 'Ref', '!BadRef' => 'BadRef'] as $key => $tag) {
                if (array_key_exists($key, $value)) {
                    return new TaggedValue($tag, self::tagReferences($value[$key]));
                }
            }
        }

        return array_map(self::tagReferences(...), $value);
    }
}
