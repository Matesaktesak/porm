<?php

declare(strict_types=1);

namespace PORM\Command;

use PORM\Migrations\Resolver;
use PORM\Migrations\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
            ->setDescription('Run any migrations that haven\'t been applied yet');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int {
        $new = $this->resolver->getNewMigrations();

        if (!$new) {
            $output->writeln("No new migrations found.");
            return 0;
        } else {
            foreach ($new as $migration) {
                $output->writeln(sprintf('Running migration %d (%s)...', $migration->version, $migration->type));
                $this->runner->run($this->resolver->getPath($migration));
                $this->resolver->markAsApplied($migration);
            }
        }

        return 0;
    }


}
