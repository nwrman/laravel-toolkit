<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Commands;

use Illuminate\Console\Command;
use Nwrman\LaravelToolkit\Tia\TiaBaselines;
use Override;

final class TiaCommand extends Command
{
    #[Override]
    protected $signature = 'toolkit:tia
        {action : check, inherit or publish}
        {--base= : The branch this one was cut from (default: what start-ticket recorded, else the default branch)}';

    #[Override]
    protected $description = 'Hand complete TIA baselines from landed branches to new ones';

    public function handle(TiaBaselines $baselines): int
    {
        /** @var string $action */
        $action = $this->argument('action');

        if (! in_array($action, ['check', 'inherit', 'publish'], true)) {
            $this->error(sprintf('Unknown action "%s". Use check, inherit or publish.', $action));

            return 2;
        }

        if (! (bool) config('toolkit.tia.enabled', false)) {
            $this->line('TIA is off (toolkit.tia.enabled): nothing to do.');

            return 0;
        }

        /** @var string|null $base */
        $base = $this->option('base');
        $root = base_path();

        if ($action === 'check') {
            $problem = $baselines->problem($root);

            if ($problem !== null) {
                $this->line(sprintf('No usable TIA baseline: %s.', $problem));

                return 1;
            }

            $this->line('The TIA baseline is usable.');

            return 0;
        }

        $this->line($action === 'inherit' ? $baselines->inherit($root, $base) : $baselines->publish($root, $base));

        return 0;
    }
}
