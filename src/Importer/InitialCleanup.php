<?php

namespace ArchLinux\ImportFluxBB\Importer;

use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InitialCleanup
{
    private ConnectionInterface $database;
    private ContainerInterface $container;

    public function __construct(ConnectionInterface $database, ContainerInterface $container)
    {
        $this->database = $database;
        $this->container = $container;
    }

    public function execute(OutputInterface $output)
    {
        $output->writeln('Initial cleanup...');

        $this->database->statement('SET FOREIGN_KEY_CHECKS=0');
        $this->database->table('users')->truncate();
        $this->database->table('access_tokens')->truncate();
        foreach (glob($this->container[Paths::class]->public . '/assets/avatars/*.*') as $avatar) {
            unlink($avatar);
        }
        // $this->database->table('groups')->truncate();
        $this->database->table('group_user')->truncate();

        $this->database->table('tags')->truncate();
        $this->database->table('discussions')->truncate();
        $this->database->table('discussion_tag')->truncate();
        $this->database->table('posts')->truncate();

        $this->database->table('discussion_user')->truncate();
        $this->database->table('tag_user')->truncate();
        $this->database->table('flags')->truncate();

        $this->database->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
