<?php

declare(strict_types=1);

namespace Tests\Commands;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayloadCodecSchemaContractTest extends TestCase
{
    /**
     * @var array<string, array{type: string, const: string}>
     */
    private const CODEC_FIELDS = [
        'workflow-query.schema.json#/properties/result_envelope/properties/codec' => [
            'type' => 'string',
            'const' => 'avro',
        ],
        'workflow-run.schema.json#/properties/payload_codec' => [
            'type' => 'string',
            'const' => 'avro',
        ],
        'workflow-start.schema.json#/properties/payload_codec' => [
            'type' => 'string',
            'const' => 'avro',
        ],
        'workflow-update.schema.json#/properties/result_envelope/properties/codec' => [
            'type' => 'string',
            'const' => 'avro',
        ],
        'workflow-update.schema.json#/properties/update_diagnostics/properties/result_envelope/properties/codec' => [
            'type' => 'string',
            'const' => 'avro',
        ],
    ];

    public function test_every_published_payload_codec_field_is_avro_only(): void
    {
        $codecFields = [];

        foreach (glob(__DIR__.'/../../schemas/output/*.schema.json') ?: [] as $path) {
            $schema = self::readSchema(basename($path));
            self::collectCodecFields($schema, basename($path).'#', $codecFields);
        }

        ksort($codecFields);

        self::assertSame(self::CODEC_FIELDS, $codecFields);
        self::assertSame(
            ['payload_codec'],
            self::readSchema('workflow-start.schema.json')['dependentRequired']['input'] ?? null,
        );
        self::assertSame(
            ['payload_codec'],
            self::readSchema('workflow-run.schema.json')['dependentRequired']['input'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $codecPath
     */
    #[DataProvider('payloadEnvelopeDocuments')]
    public function test_payload_envelopes_reject_non_avro_and_missing_codecs(
        string $schemaFile,
        array $document,
        array $codecPath,
    ): void {
        $validator = new Validator();
        $schema = self::jsonValue(self::readSchema($schemaFile));

        $result = $validator->validate(self::jsonValue($document), $schema);

        self::assertTrue($result->isValid(), self::validationErrorMessage($result));

        foreach (['json', 'customer-codec', null] as $unsupportedCodec) {
            $counterexample = self::withValueAtPath($document, $codecPath, $unsupportedCodec);

            self::assertFalse(
                $validator->validate(self::jsonValue($counterexample), $schema)->isValid(),
                sprintf('%s accepted payload codec %s.', $schemaFile, json_encode($unsupportedCodec)),
            );
        }

        $counterexample = self::withoutValueAtPath($document, $codecPath);

        self::assertFalse(
            $validator->validate(self::jsonValue($counterexample), $schema)->isValid(),
            sprintf('%s accepted a payload envelope without its codec.', $schemaFile),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function payloadEnvelopeDocuments(): iterable
    {
        yield 'workflow start input' => [
            'workflow-start.schema.json',
            [
                'namespace' => 'orders',
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'outcome' => 'started',
                'input' => [['order_id' => 42]],
                'payload_codec' => 'avro',
            ],
            ['payload_codec'],
        ];

        yield 'workflow run input' => [
            'workflow-run.schema.json',
            [
                'namespace' => 'orders',
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'input' => [['order_id' => 42]],
                'payload_codec' => 'avro',
            ],
            ['payload_codec'],
        ];

        yield 'workflow query result envelope' => [
            'workflow-query.schema.json',
            [
                'namespace' => 'orders',
                'result' => ['status' => 'ready'],
                'result_envelope' => [
                    'codec' => 'avro',
                    'blob' => 'c29tZSBhdnJvIGJ5dGVz',
                ],
            ],
            ['result_envelope', 'codec'],
        ];

        $workflowUpdateDocument = [
            'namespace' => 'orders',
            'workflow_id' => 'wf-123',
            'update_name' => 'change-address',
            'outcome' => 'completed',
            'update_diagnostics' => [
                'result_envelope' => [
                    'codec' => 'avro',
                    'blob' => 'c29tZSBhdnJvIGJ5dGVz',
                ],
            ],
            'cli_fields' => [
                'surface' => 'workflow:update --json',
                'fields_present' => [],
                'state' => 'completed',
                'request_id' => null,
                'update_id' => 'update-123',
                'outcome' => 'completed',
                'reason' => null,
                'payload' => null,
                'result' => ['accepted' => true],
                'error' => null,
                'history_references' => null,
            ],
            'result_envelope' => [
                'codec' => 'avro',
                'blob' => 'c29tZSBhdnJvIGJ5dGVz',
            ],
        ];

        yield 'workflow update result envelope' => [
            'workflow-update.schema.json',
            $workflowUpdateDocument,
            ['result_envelope', 'codec'],
        ];

        yield 'workflow update diagnostics result envelope' => [
            'workflow-update.schema.json',
            $workflowUpdateDocument,
            ['update_diagnostics', 'result_envelope', 'codec'],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, array{type: string, const: string}>  $codecFields
     */
    private static function collectCodecFields(array $schema, string $path, array &$codecFields): void
    {
        foreach ($schema as $keyword => $value) {
            if (! is_array($value)) {
                continue;
            }

            $childPath = $path.'/'.self::escapeJsonPointer((string) $keyword);

            if (
                str_ends_with($path, '/properties')
                && in_array($keyword, ['codec', 'payload_codec'], true)
            ) {
                $codecFields[$childPath] = [
                    'type' => $value['type'] ?? null,
                    'const' => $value['const'] ?? null,
                ];
            }

            self::collectCodecFields($value, $childPath, $codecFields);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function readSchema(string $file): array
    {
        $contents = file_get_contents(__DIR__.'/../../schemas/output/'.$file);
        self::assertIsString($contents);

        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $path
     *
     * @return array<string, mixed>
     */
    private static function withValueAtPath(array $document, array $path, mixed $value): array
    {
        $cursor = &$document;

        foreach (array_slice($path, 0, -1) as $segment) {
            $cursor = &$cursor[$segment];
        }

        $cursor[$path[array_key_last($path)]] = $value;

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $path
     *
     * @return array<string, mixed>
     */
    private static function withoutValueAtPath(array $document, array $path): array
    {
        $cursor = &$document;

        foreach (array_slice($path, 0, -1) as $segment) {
            $cursor = &$cursor[$segment];
        }

        unset($cursor[$path[array_key_last($path)]]);

        return $document;
    }

    private static function jsonValue(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function validationErrorMessage(ValidationResult $result): string
    {
        $error = $result->error();

        return $error === null
            ? ''
            : json_encode((new ErrorFormatter())->format($error), JSON_THROW_ON_ERROR);
    }

    private static function escapeJsonPointer(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
