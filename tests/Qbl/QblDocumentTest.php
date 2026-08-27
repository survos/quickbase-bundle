<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests\Qbl;

use PHPUnit\Framework\TestCase;
use Survos\Quickbase\Qbl\QblDocument;
use Symfony\Component\Yaml\Tag\TaggedValue;

final class QblDocumentTest extends TestCase
{
    public function testBuildsPortableQblWithNativeReferences(): void
    {
        $document = QblDocument::fromArray([
            'Version' => '0.14',
            'Resources' => [
                '$App_Lions' => [
                    'Type' => 'QB::Application',
                    'Properties' => ['Name' => 'Lions Service and Loan Closet'],
                    'Tables' => [
                        '$Table_Equipment' => [
                            'Type' => 'QB::Table',
                            'Properties' => ['Name' => 'Equipment'],
                            'Forms' => [
                                '$Form_Equipment' => [
                                    'Type' => 'QB::FormV2',
                                    'Properties' => ['Name' => 'Equipment'],
                                    'Field' => ['!Ref' => ['Field' => '$Field_Photo']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $yaml = $document->toYaml();
        self::assertStringContainsString("Version: '0.14'", $yaml);
        self::assertStringContainsString("Type: 'QB::FormV2'", $yaml);
        self::assertStringContainsString("Field: !Ref\n", $yaml);
        self::assertStringContainsString('Field: $Field_Photo', $yaml);

        $roundTrip = QblDocument::fromYaml($yaml)->toArray();
        self::assertInstanceOf(TaggedValue::class, $roundTrip['Resources']['$App_Lions']['Tables']['$Table_Equipment']['Forms']['$Form_Equipment']['Field']);
    }

    public function testRejectsMissingResources(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QblDocument::fromArray(['Version' => '0.14']);
    }
}
