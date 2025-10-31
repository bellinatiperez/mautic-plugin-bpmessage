<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProcessBpMessageQueuesCommand extends Command
{
    public function __construct(private BpMessageModel $model, private TranslatorInterface $translator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:process')
            ->setDescription('Processa filas de BpMessage em lote')
            ->addArgument('config-hash', InputArgument::OPTIONAL, 'Processar apenas filas desta configuração')
            ->addOption('batch-size', 'l', InputOption::VALUE_OPTIONAL, 'Tamanho do lote para exibição (apenas relatório)', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hash = $input->getArgument('config-hash');
        $batchSize = (int) $input->getOption('batch-size');

        $output->writeln('<info>Disparando filas BpMessage</info>');
        if ($hash) {
            $output->writeln(sprintf('<comment>Filtro de configuração:</comment> %s', $hash));
        }

        $output->writeln('<comment>Disparando lotes pendentes</comment>');
        $report = $this->model->processPending($hash ?: null);

        $totalToProcess = (int) ($report['eligible'] ?? 0);
        $processed      = (int) ($report['processed'] ?? 0);
        $scheduled      = (int) ($report['scheduled'] ?? 0);

        $output->writeln(sprintf('%d total contact(s) to be added in batches of %d', $totalToProcess, $batchSize));
        $output->writeln(sprintf('%d total events were executed', $processed));
        $output->writeln(sprintf('%d total events were scheduled', $scheduled));

        $output->writeln('<info>Processamento concluído.</info>');
        return Command::SUCCESS;
    }
}