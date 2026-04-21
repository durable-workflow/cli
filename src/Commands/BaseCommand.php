<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use DurableWorkflow\Cli\Support\OutputMode;
use DurableWorkflow\Cli\Support\ProfileResolver;
use DurableWorkflow\Cli\Support\ProfileStore;
use DurableWorkflow\Cli\Support\ResolvedConnection;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerException;
use DurableWorkflow\Cli\Support\ServerHttpException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    private const INPUT_ENCODINGS = ['json', 'raw', 'base64'];

    private ?ServerClient $serverClient = null;

    private ?ProfileStore $profileStore = null;

    protected function configure(): void
    {
        $this->addOption('server', 's', InputOption::VALUE_OPTIONAL, 'Server URL', null);
        $this->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Target namespace', null);
        $this->addOption('token', null, InputOption::VALUE_OPTIONAL, 'Auth token', null);
        $this->addOption(
            'env',
            null,
            InputOption::VALUE_REQUIRED,
            'Named dw environment to use (overrides $DW_ENV and any env set via `dw env:use`). Hard-fails when the profile does not exist.',
            null,
        );
        $this->registerOutputOption();
    }

    /**
     * Add the shared `--output` option. Kept as a separate helper so
     * local-only commands (e.g. `dw env:*`, which never talk to the
     * server) can opt in to the output contract without also picking
     * up the server/namespace/token/env options.
     */
    protected function registerOutputOption(): void
    {
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: table (human-readable), json (single JSON document), jsonl (one JSON object per line, for list commands)',
            OutputMode::TABLE,
            OutputMode::ALL,
        );
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleException $e) {
            throw $e;
        } catch (InvalidOptionException $e) {
            return $this->emitError($input, $output, $e, $e->exitCode());
        } catch (ServerException $e) {
            return $this->emitError($input, $output, $e, $e->exitCode());
        } catch (\Throwable $e) {
            return $this->emitError($input, $output, $e, ExitCode::FAILURE);
        }
    }

    /**
     * Hard-fail before any execute() runs when `--output` is set to a value
     * outside the published contract — never let scripts proceed on a
     * silently-ignored mode.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->outputMode($input);
    }

    protected function client(InputInterface $input): ServerClient
    {
        if ($this->serverClient instanceof ServerClient) {
            return $this->serverClient;
        }

        $resolved = $this->resolvedConnection($input);

        return new ServerClient(
            baseUrl: $resolved->server,
            token: $resolved->token,
            namespace: $resolved->namespace,
            tlsVerify: $resolved->tlsVerify,
        );
    }

    public function setServerClient(ServerClient $serverClient): void
    {
        $this->serverClient = $serverClient;
    }

    public function setProfileStore(ProfileStore $store): void
    {
        $this->profileStore = $store;
    }

    protected function profileStore(): ProfileStore
    {
        if ($this->profileStore instanceof ProfileStore) {
            return $this->profileStore;
        }

        $this->profileStore = new ProfileStore();

        return $this->profileStore;
    }

    protected function resolvedConnection(InputInterface $input): ResolvedConnection
    {
        return (new ProfileResolver($this->profileStore()))->resolve(
            flagEnv: $this->optionValue($input, 'env'),
            flagServer: $this->optionValue($input, 'server'),
            flagNamespace: $this->optionValue($input, 'namespace'),
            flagToken: $this->optionValue($input, 'token'),
        );
    }

    private function optionValue(InputInterface $input, string $name): ?string
    {
        if (! $input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    protected function validateControlPlaneOption(
        ServerClient $client,
        OutputInterface $output,
        string $operation,
        string $field,
        ?string $value,
        string $optionName,
    ): bool {
        if ($value === null) {
            return true;
        }

        try {
            $client->assertControlPlaneOptionValue(
                operation: $operation,
                field: $field,
                value: $value,
                optionName: $optionName,
            );
        } catch (\RuntimeException $exception) {
            $this->writeHumanError($output, $exception->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Add the common caller-payload input contract used by workflow, schedule,
     * and externally-completed activity commands.
     */
    protected function addInputOptions(string $description = 'Input payload'): void
    {
        $this->addOption('input', 'i', InputOption::VALUE_OPTIONAL, $description.' (inline)');
        $this->addOption('input-file', null, InputOption::VALUE_OPTIONAL, $description.' file path; use - for stdin');
        $this->addOption(
            'input-encoding',
            null,
            InputOption::VALUE_OPTIONAL,
            'Input encoding: json (default), raw, base64',
            'json',
            self::INPUT_ENCODINGS,
        );
    }

    /**
     * Parse the common input options into the payload value the caller supplied.
     *
     * `json` decodes a JSON document. `raw` passes text through unchanged.
     * `base64` decodes strict base64 to a binary-safe PHP string.
     */
    protected function parseInputOption(InputInterface $input): mixed
    {
        [$value, $source] = $this->inputSource($input);

        if ($value === null) {
            return null;
        }

        return match ($this->inputEncoding($input)) {
            'json' => $this->decodeJsonDocument($value, $source),
            'raw' => $value,
            'base64' => $this->decodeBase64Document($value, $source),
        };
    }

    /**
     * Parse the common input options for server fields that represent workflow
     * argument lists. Scalars, raw text, and base64-decoded text become one
     * positional argument so the HTTP request still satisfies the v2 `input`
     * array contract.
     *
     * @return array<int|string, mixed>|null
     */
    protected function parseInputArgumentsOption(InputInterface $input): ?array
    {
        $value = $this->parseInputOption($input);

        if ($value === null) {
            return null;
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * Parse a user-supplied option value that must be a JSON document.
     *
     * Returns null when the option was not provided. Throws
     * {@see InvalidOptionException} (which maps to `ExitCode::INVALID`)
     * when the string is not valid JSON — plain `json_decode` silently
     * returns `null` on failure, which is indistinguishable from a
     * successful null decode and ends up as a generic `FAILURE` exit.
     */
    protected function parseJsonOption(?string $value, string $optionName): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->decodeJsonDocument($value, '--'.$optionName);
    }

    private function decodeJsonDocument(string $value, string $source): mixed
    {
        try {
            return json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidOptionException(sprintf(
                '%s must be valid JSON: %s',
                $source,
                $e->getMessage(),
            ));
        }
    }

    private function decodeBase64Document(string $value, string $source): string
    {
        $decoded = base64_decode(trim($value), true);

        if ($decoded === false) {
            throw new InvalidOptionException(sprintf(
                '%s must be valid base64 when --input-encoding=base64.',
                $source,
            ));
        }

        return $decoded;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function inputSource(InputInterface $input): array
    {
        $inline = $input->hasOption('input') ? $input->getOption('input') : null;
        $file = $input->hasOption('input-file') ? $input->getOption('input-file') : null;
        $hasInline = is_string($inline) && $inline !== '';
        $hasFile = is_string($file) && $file !== '';

        if ($hasInline && $hasFile) {
            throw new InvalidOptionException('Use either --input or --input-file, not both.');
        }

        if ($hasFile) {
            return [$this->readInputFile($file), '--input-file'];
        }

        if ($hasInline) {
            return [$inline, '--input'];
        }

        return [null, '--input'];
    }

    private function readInputFile(string $path): string
    {
        if ($path === '-') {
            $contents = stream_get_contents(STDIN);

            if ($contents === false) {
                throw new InvalidOptionException('--input-file could not read from stdin.');
            }

            return $contents;
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidOptionException(sprintf('--input-file must be a readable file: %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidOptionException(sprintf('--input-file could not be read: %s', $path));
        }

        return $contents;
    }

    private function inputEncoding(InputInterface $input): string
    {
        $encoding = $input->hasOption('input-encoding')
            ? (string) ($input->getOption('input-encoding') ?? 'json')
            : 'json';

        if (! in_array($encoding, self::INPUT_ENCODINGS, true)) {
            throw new InvalidOptionException(sprintf(
                '--input-encoding must be one of: %s',
                implode(', ', self::INPUT_ENCODINGS),
            ));
        }

        return $encoding;
    }

    /**
     * Emit a single JSON document on stdout. Compact (no pretty-print):
     * scripts piping through `jq` get one parseable object per invocation,
     * and `--output=jsonl` list commands can embed the same encoder.
     */
    protected function renderJson(OutputInterface $output, array $data): int
    {
        $output->writeln(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * Emit a list envelope, honouring the caller's output mode:
     *
     *   - `jsonl` → one JSON object per line from `$envelope[$itemsKey]`
     *     (metadata in $envelope is dropped; each line stands alone).
     *   - anything else → the full envelope as a single JSON document so
     *     pagination cursors and counts survive.
     *
     * Use this on commands that return a bounded list; streaming-unaware
     * list commands can keep `renderJson()` until they adopt jsonl.
     */
    protected function renderJsonList(
        OutputInterface $output,
        InputInterface $input,
        array $envelope,
        string $itemsKey,
    ): int {
        if ($this->outputMode($input) === OutputMode::JSONL) {
            $items = $envelope[$itemsKey] ?? [];
            foreach ($items as $item) {
                $output->writeln(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            return Command::SUCCESS;
        }

        return $this->renderJson($output, $envelope);
    }

    /**
     * Declare --json on a mutating command. Pair with {@see wantsJson()} in
     * execute() to return the raw server response instead of the human-readable
     * summary. Equivalent to `--output=json`; retained as a short alias.
     */
    protected function addJsonOption(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON (alias for --output=json)');
    }

    protected function wantsJson(InputInterface $input): bool
    {
        if ($input->hasOption('json') && $input->getOption('json') === true) {
            return true;
        }

        return OutputMode::isMachineReadable($this->resolveOutputMode($input));
    }

    /**
     * Resolve the effective output mode. Throws {@see InvalidOptionException}
     * for unknown values so scripts get a clean INVALID exit rather than a
     * silent fallback.
     */
    protected function outputMode(InputInterface $input): string
    {
        $mode = $this->resolveOutputMode($input);

        if (! in_array($mode, OutputMode::ALL, true)) {
            throw new InvalidOptionException(sprintf(
                '--output must be one of: %s',
                implode(', ', OutputMode::ALL),
            ));
        }

        return $mode;
    }

    protected function renderTable(OutputInterface $output, array $headers, array $rows): void
    {
        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * Machine-readable mode: emit a JSON envelope on stdout so scripts
     * have a parseable response on both success and failure. Human mode:
     * route the message to stderr when the output supports split streams.
     */
    private function emitError(InputInterface $input, OutputInterface $output, \Throwable $e, int $exitCode): int
    {
        $jsonRequested = false;

        try {
            $jsonRequested = $this->wantsJson($input);
        } catch (\Throwable) {
            // Fall back to human routing if mode resolution itself failed.
        }

        if ($jsonRequested) {
            $output->writeln($this->encodeErrorEnvelope($e, $exitCode));

            return $exitCode;
        }

        $this->writeHumanError($output, $e->getMessage());

        return $exitCode;
    }

    private function encodeErrorEnvelope(\Throwable $e, int $exitCode): string
    {
        $envelope = [
            'error' => $e->getMessage(),
            'exit_code' => $exitCode,
        ];

        if ($e instanceof ServerHttpException) {
            $envelope['status_code'] = $e->statusCode;
        }

        return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function writeHumanError(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln('<error>'.$message.'</error>');
    }

    private function resolveOutputMode(InputInterface $input): string
    {
        if (! $input->hasOption('output')) {
            return OutputMode::TABLE;
        }

        if ($input->hasOption('json') && $input->getOption('json') === true) {
            return OutputMode::JSON;
        }

        $mode = $input->getOption('output');

        if ($this->optionWasProvided($input, 'output')) {
            if ($mode === null || $mode === '') {
                return OutputMode::TABLE;
            }

            return (string) $mode;
        }

        if ($input->hasOption('env')) {
            $profileOutput = $this->resolvedConnection($input)->profile?->output;
            if ($profileOutput !== null && $profileOutput !== '') {
                return $profileOutput;
            }
        }

        if ($mode === null || $mode === '') {
            return OutputMode::TABLE;
        }

        return (string) $mode;
    }

    private function optionWasProvided(InputInterface $input, string $name): bool
    {
        return $input->hasParameterOption('--'.$name, true);
    }
}
