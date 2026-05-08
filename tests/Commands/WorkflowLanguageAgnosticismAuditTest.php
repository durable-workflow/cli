<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

final class WorkflowLanguageAgnosticismAuditTest extends TestCase
{
    public function test_control_plane_fixtures_do_not_encode_php_only_wire_contracts(): void
    {
        foreach (self::controlPlaneFixturePaths() as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents, "{$path} must be readable.");

            $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($fixture);
            self::assertSame(
                'durable-workflow.polyglot.control-plane-request-fixture',
                $fixture['schema'] ?? null,
                basename($path).' must remain a shared polyglot fixture.',
            );

            self::assertNoPhpOnlyContractValue($fixture, basename($path));
        }
    }

    public function test_no_command_option_default_advertises_a_specific_runtime(): void
    {
        $languageDefaults = [
            'php',
            'laravel',
            'python',
            'go',
            'java',
            'typescript',
            'javascript',
            'node',
            'ruby',
            'rust',
            'csharp',
            '.net',
        ];

        $application = new Application();
        $offenders = [];

        foreach ($application->all() as $name => $command) {
            if (! $command instanceof Command) {
                continue;
            }

            foreach ($command->getDefinition()->getOptions() as $option) {
                if (! $option instanceof InputOption) {
                    continue;
                }

                $default = $option->getDefault();
                if (! is_string($default) || $default === '') {
                    continue;
                }

                $normalized = strtolower($default);
                foreach ($languageDefaults as $token) {
                    if ($normalized === $token) {
                        $offenders[] = sprintf(
                            '%s: --%s defaults to "%s"',
                            $name,
                            $option->getName(),
                            $default,
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "CLI options must not default to a specific language runtime; the CLI is the language-neutral surface for the control plane:\n  - "
                .implode("\n  - ", $offenders),
        );
    }

    /**
     * @return list<string>
     */
    private static function controlPlaneFixturePaths(): array
    {
        $paths = glob(__DIR__.'/../fixtures/control-plane/*.json');
        self::assertNotFalse($paths);
        self::assertNotSame([], $paths);
        sort($paths);

        return array_values($paths);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $values
     */
    private static function assertNoPhpOnlyContractValue(array $values, string $context): void
    {
        foreach ($values as $key => $value) {
            $path = "{$context}.{$key}";

            if (is_array($value)) {
                self::assertNoPhpOnlyContractValue($value, $path);

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            foreach (self::phpOnlyWireMarkers() as $label => $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $value,
                    "{$path} contains {$label}; shared CLI/SDK fixtures must stay language-neutral.",
                );
            }
        }
    }

    /**
     * @return array<string, non-empty-string>
     */
    private static function phpOnlyWireMarkers(): array
    {
        return [
            'PHP runtime token' => '/(^|[^a-z0-9])php([^a-z0-9]|$)/i',
            'PHP serialization marker' => '/(?:php[-_ ]?)?serialize|unserialize/i',
            'PHP source filename' => '/\\.php\\b/i',
            'PHP stream or MIME type' => '/php:\\/\\/|application\\/x-php/i',
            'Laravel or Artisan framework marker' => '/\\b(?:laravel|artisan)\\b/i',
            'Composer or vendor path marker' => '/\\bcomposer\\b|vendor[\\/\\\\]/i',
            'PHP namespace-like class name' => '/[A-Z][A-Za-z0-9_]*\\\\\\\\[A-Z][A-Za-z0-9_\\\\\\\\]*/',
        ];
    }
}
