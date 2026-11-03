<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Preflight;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Laravel\AgentDetector\AgentDetector;
use Nwrman\LaravelToolkit\Config\ToolkitConfig;
use Nwrman\LaravelToolkit\Tia\PestCache;
use Nwrman\LaravelToolkit\Tia\TiaBaselines;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `vendor/bin/preflight`: the console entry point, run in the project's root.
 */
final class PreflightCommand extends Command
{
    public function __construct(private readonly string $root)
    {
        parent::__construct('preflight');
    }

    #[Override]
    protected function configure(): void
    {
        $this->setDescription('Run every CI gate in parallel, stopping at the first failure')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Run only the gates that did not pass last run')
            ->addOption('gate', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these gates (repeat for more)')
            ->addOption('force-build', null, InputOption::VALUE_NONE, 'Rebuild the frontend assets before Browser specs run')
            ->addOption('no-notify', null, InputOption::VALUE_NONE, 'Skip the macOS notification');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = new Filesystem;
        $process = new Factory;

        /** @var array<int, string> $gates */
        $gates = $input->getOption('gate');

        $preflight = new Preflight(
            $this->root,
            ToolkitConfig::load($this->root),
            $output,
            $process,
            $files,
            new ProcessTree($process),
            new TiaBaselines(new PestCache($process), $files, $process),
            AgentDetector::detect()->isAgent,
        );

        return $preflight->run(
            (bool) $input->getOption('retry'),
            $gates,
            (bool) $input->getOption('force-build'),
            ! (bool) $input->getOption('no-notify'),
        );
    }
}
