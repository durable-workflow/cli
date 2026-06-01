<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SearchAttributeCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('search-attribute:list')
            ->setAliases(['search-attributes:list'])
            ->setDescription('List search attribute definitions')
            ->setHelp(<<<'HELP'
List every search attribute — system-defined attributes shipped by the
server and any custom attributes registered for this namespace. When
<comment>--namespace</comment> is omitted, the command queries the
resolved default namespace only; it never enumerates all namespaces.

<comment>Examples:</comment>

  <info>dw search-attributes list</info>
  <info>dw search-attribute:list</info>
  <info>dw search-attribute:list --namespace=orders</info>
  <info>dw search-attributes list --output=json | jq '.custom_attributes'</info>
  <info>dw search-attribute:list --output=json | jq '.custom_attributes'</info>
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->addNamespaceContext($input, $this->client($input)->get('/search-attributes'));

        if ($this->wantsJson($input)) {
            // system_attributes / custom_attributes are name→type maps, not
            // lists, so jsonl would produce nonsense. Stick to a single doc.
            return $this->renderJson($output, $result);
        }

        $rows = [];

        foreach ($result['system_attributes'] ?? [] as $name => $type) {
            $rows[] = [$name, $type, 'system'];
        }

        foreach ($result['custom_attributes'] ?? [] as $name => $type) {
            $rows[] = [$name, $type, 'custom'];
        }

        $namespace = $this->namespaceContext($input, $result);

        if (empty($rows)) {
            $output->writeln(sprintf('<comment>No search attributes found in namespace %s.</comment>', $namespace));

            return Command::SUCCESS;
        }

        $output->writeln('Namespace: '.$namespace);
        $this->renderTable($output, ['Name', 'Type', 'Source'], $rows);

        return Command::SUCCESS;
    }
}
