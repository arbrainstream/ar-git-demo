<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;

/**
 * Database Migrations with Under Maintenance.
 * 
 * Under development. Not yet completed.
 * The event listener is responsible to put the website in
 * maintenance mode while DB migrations are taking place.
 * 
 * @author Victor Diaz
 * @since March 1, 2021
 */
class MigrateDatabaseCommand extends Command {

    protected static $defaultName = 'app:migrate-db';
    private $em;
    private $params;
    private $cache;

    public function __construct(EntityManagerInterface $em, ParameterBagInterface $params, CacheInterface $cache) {
        parent::__construct();
        $this->em = $em;
        $this->params = $params;
        $this->cache = $cache;
    }

    protected function configure() {
        $this
                ->setDescription('Migrating Databases during maintenance mode.')
//                ->addOption('db', null, InputOption::VALUE_REQUIRED, 'The database connection to use for this command.')
//                ->addOption('em', null, InputOption::VALUE_REQUIRED, 'The entity manager to use for this command.')
//                ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'The shard connection to use for this command.')
                ->setHelp(
                        'This command allows a database to be migrated on a ' .
                        'multi-environment system.'
                )
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): int {
        $result = Command::FAILURE;

        $io = new SymfonyStyle($input, $output);

        if ($this->cache->get('migrating')) {
            $io->note('Another instance locked the migration');

            return Command::SUCCESS;
        }

        // Take the responsibility to execute the migration
        $io->text('Locking');
        $this->cache->set('migrating', true, 60);
        $io->text('Lock obtained');

        // Check whether there are migrations to execute or not
        $io->text('Loading migrations');

        $app = $this->getApplication();
        DoctrineCommandHelper::setApplicationHelper($app, $input);

        DoctrineCommand::configureMigrations(
                $app->getKernel()->getContainer(),
                $this->getMigrationConfiguration($input, $output)
        );

        $configuration = $this->getMigrationConfiguration($input, $output);
        $toExecute = array_diff(
                $configuration->getAvailableVersions(),
                $configuration->getMigratedVersions()
        );

        if (!$toExecute) {
            // No migration to execute: do not enable maintenance
            $io->note('No migration to execute');

            $io->text('Releasing lock');
            $this->cache->delete('migrating');
            $io->text('Lock released');

            return Command::SUCCESS;
        }

        // Migrations to execute: enable maintenance and run them
        $io->text('Migration(s) to execute: ' . implode(', ', $toExecute));

        $io->text('Enabling maintenance mode');
        $this->cache->set('maintenance', true, 60);
        $io->text('Maintenance enabled');

        $io->text("Executing the migration(s)\n");

        // Enable full output and disable the migration question
        $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        $input->setOption('no-interaction', true);

        parent::execute($input, $output);

        $output->write("\n");
        $io->text('Migration(s) executed');

        $io->text('Disabling maintenance mode');
        $this->cache->delete('maintenance');
        $io->text('Maintenance disabled');

        $io->text('Releasing lock');
        $this->cache->delete('migrating');
        $io->text('Lock released');

        $io->success('Database migration completed.');

        return Command::SUCCESS;
    }

}
