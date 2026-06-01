<?php

declare(strict_types=1);

namespace EbizChargeShopware\Command;

use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Connection\ConnectionTestService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly PluginConfigProvider $pluginConfigProvider,
        private readonly ConnectionTestService $connectionTestService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel-id', null, InputOption::VALUE_OPTIONAL, 'Optional Shopware sales channel ID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $salesChannelId = $input->getOption('sales-channel-id');
        $config = $this->pluginConfigProvider->get(is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null);
        $result = $this->connectionTestService->test($config, is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null);

        $io->definitionList(
            ['Environment' => $result->environment],
            ['Endpoint' => $result->endpoint],
            ['Credential fingerprint' => $result->credentialFingerprint],
            ['Tested at (UTC)' => $result->testedAt],
            ['Failure category' => $result->failureCategory ?? 'none']
        );

        if ($result->success) {
            $io->success($result->message);

            return Command::SUCCESS;
        }

        $io->error($result->message);

        return Command::FAILURE;
    }
}
