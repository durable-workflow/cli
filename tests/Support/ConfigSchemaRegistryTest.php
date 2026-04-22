<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ConfigSchemaRegistry;
use PHPUnit\Framework\TestCase;

class ConfigSchemaRegistryTest extends TestCase
{
    public function test_external_executor_schema_is_registered(): void
    {
        self::assertTrue(ConfigSchemaRegistry::hasSchema('external-executor-config'));

        $entry = ConfigSchemaRegistry::entry('external-executor-config');

        self::assertSame('schemas/config/external-executor.schema.json', $entry['schema']);
        self::assertContains(
            'examples/external-executor/operator-task-runner.dw-executor.json',
            $entry['examples'],
        );
    }

    public function test_external_executor_schema_defines_carrier_neutral_contract(): void
    {
        $schema = ConfigSchemaRegistry::schema('external-executor-config');

        self::assertSame('durable-workflow.external-executor.config', $schema['properties']['schema']['const']);
        self::assertSame(['schema', 'version', 'mappings', 'carriers'], $schema['required']);
        self::assertContains('process', $schema['properties']['carriers']['additionalProperties']['properties']['type']['enum']);
        self::assertContains('http', $schema['properties']['carriers']['additionalProperties']['properties']['type']['enum']);
        self::assertContains('invocable_http', $schema['properties']['carriers']['additionalProperties']['properties']['type']['enum']);
        self::assertContains('signed_headers', $schema['properties']['auth_refs']['additionalProperties']['properties']['type']['enum']);
        self::assertArrayHasKey('header_allowlist', $schema['properties']['auth_refs']['additionalProperties']['properties']);
        self::assertContains('activity', $schema['$defs']['mapping']['properties']['kind']['enum']);
        self::assertContains('workflow_start', $schema['$defs']['mapping']['properties']['kind']['enum']);
        self::assertContains('unknown_carrier', $schema['x-durable-workflow-validation']['named_errors']);
        self::assertContains('unsupported_carrier_capability', $schema['x-durable-workflow-validation']['named_errors']);
    }

    public function test_published_examples_use_the_schema_and_unique_mapping_names(): void
    {
        $manifest = ConfigSchemaRegistry::manifest();
        $examples = $manifest['schemas']['external-executor-config']['examples'] ?? [];

        self::assertNotSame([], $examples);

        foreach ($examples as $relativePath) {
            self::assertIsString($relativePath);

            $contents = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);
            self::assertIsString($contents);

            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            self::assertSame('durable-workflow.external-executor.config', $decoded['schema']);
            self::assertSame(1, $decoded['version']);
            self::assertIsArray($decoded['carriers']);
            self::assertIsArray($decoded['mappings']);
            self::assertNotSame([], $decoded['mappings']);

            $names = array_column($decoded['mappings'], 'name');
            self::assertSame($names, array_values(array_unique($names)), $relativePath.' has duplicate mapping names.');

            foreach ($decoded['mappings'] as $mapping) {
                self::assertArrayHasKey((string) $mapping['carrier'], $decoded['carriers']);
            }
        }
    }
}
