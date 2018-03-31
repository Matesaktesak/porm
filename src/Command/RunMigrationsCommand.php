<?php

declare(strict_types=1);

namespace PORM\Command;

use PORM\Migrations\Resolver;
use PORM\Migrations\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class RunMigrationsCommand extends Command {

    private $resolver;

    private $runner;


    public function __construct(Resolver $resolver, Runner $runner) {
        parent::__construct();
        $this->resolver = $resolver;
        $this->runner = $runner;
    }

    protected function configure() : void {
        $this->setName('porm:migrations:run')
            ->setDescription('Run any migrations that haven\'t been applied yet')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Dry run (show which migrations haven\'t been applied yet)');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int {
        $new = $this->resolver->getNewMigrations();
        $dryRun = $input->getOption('dry-run');
        $msg = $dryRun ? 'Migration %d (%s) hasn\'t been applied yet.' : 'Running migration %d (%s)...';

        if (!$new) {
            $output->writeln("No new migrations found.");
            return 0;
        } else {
            foreach ($new as $migration) {
                $output->writeln(sprintf($msg, $migration->version, $migration->type));

                if (!$dryRun) {
                    $this->runner->run($this->resolver->getPath($migration));
                    $this->resolver->markAsApplied($migration);
                }
            }
        }

        return 0;
    }


}
