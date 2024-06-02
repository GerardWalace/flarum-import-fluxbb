<?php

namespace ArchLinux\ImportFluxBB\Console;

use ArchLinux\ImportFluxBB\Importer\Avatars;
use ArchLinux\ImportFluxBB\Importer\Bans;
use ArchLinux\ImportFluxBB\Importer\Categories;
use ArchLinux\ImportFluxBB\Importer\Forums;
use ArchLinux\ImportFluxBB\Importer\ForumSubscriptions;
use ArchLinux\ImportFluxBB\Importer\Groups;
use ArchLinux\ImportFluxBB\Importer\InitialCleanup;
use ArchLinux\ImportFluxBB\Importer\PostMentionsUser;
use ArchLinux\ImportFluxBB\Importer\Posts;
use ArchLinux\ImportFluxBB\Importer\Reports;
use ArchLinux\ImportFluxBB\Importer\Topics;
use ArchLinux\ImportFluxBB\Importer\TopicSubscriptions;
use ArchLinux\ImportFluxBB\Importer\Users;
use ArchLinux\ImportFluxBB\Importer\Validation;
use Flarum\Console\AbstractCommand;
use Flarum\Extension\ExtensionManager;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Database\Capsule\Manager;

class ImportFromFluxBB extends AbstractCommand
{
    private Users $users;
    private Avatars $avatars;
    private Categories $categories;
    private Forums $forums;
    private Topics $topics;
    private Posts $posts;
    private TopicSubscriptions $topicSubscriptions;
    private ForumSubscriptions $forumSubscriptions;
    private Groups $groups;
    private Bans $bans;
    private Reports $reports;
    private PostMentionsUser $postMentionsUser;
    private InitialCleanup $initialCleanup;
    private Validation $validation;
    private ExtensionManager $extensionManager;
    private Manager $DBmanager;
    protected String $avatarsDir;

    public function __construct(
        Users $users,
        Categories $categories,
        Forums $forums,
        Avatars $avatars,
        Topics $topics,
        Posts $posts,
        TopicSubscriptions $topicSubscriptions,
        ForumSubscriptions $forumSubscriptions,
        Groups $groups,
        Bans $bans,
        Reports $reports,
        PostMentionsUser $postMentionsUser,
        InitialCleanup $initialCleanup,
        Validation $validation,
        ExtensionManager $extensionManager,
        Manager $DBmanager
    ) {
        $this->users = $users;
        $this->categories = $categories;
        $this->forums = $forums;
        $this->avatars = $avatars;
        $this->topics = $topics;
        $this->posts = $posts;
        $this->topicSubscriptions = $topicSubscriptions;
        $this->forumSubscriptions = $forumSubscriptions;
        $this->groups = $groups;
        $this->bans = $bans;
        $this->reports = $reports;
        $this->postMentionsUser = $postMentionsUser;
        $this->initialCleanup = $initialCleanup;
        $this->validation = $validation;
        $this->extensionManager = $extensionManager;
        $this->DBmanager = $DBmanager;
        parent::__construct();
    }

    protected function configure()
    {
        // For inspiration see:
        // https://github.com/sineld/import-from-fluxbb-to-flarum
        // https://github.com/mondediefr/fluxbb_to_flarum
        // also https://github.com/pierres/ll/blob/fluxbb/FluxImport.php
        $this
            ->setName('app:import-from-fluxbb')
            ->setDescription('Import from FluxBB')
            ->addArgument('fluxbb-dir', InputArgument::OPTIONAL, '', 'fluxbb');
    }

    protected function fire()
    {
        $this->checkRequiredExtension();
        ini_set('memory_limit', '16G');

        // Load second database connection, the FluxBB Database
        $this->getFluxBBDBConnection($this->input->getArgument('fluxbb-dir'));
        $this->checkFluxBBAvatarDir($this->input->getArgument('fluxbb-dir'));
        // Clean Flarum DB and avatars
        $this->initialCleanup->execute($this->output);

        // Import users, groups AND Avatars
        $this->users->execute($this->output);
        $this->avatars->execute($this->output, $this->avatarsDir);
        // $this->groups->execute($this->output);

        // Start import the Forum
        $this->categories->execute($this->output);
        $this->forums->execute($this->output);
        $this->topics->execute($this->output);
        $this->posts->execute($this->output);

        // $this->topicSubscriptions->execute($this->output);
        // $this->forumSubscriptions->execute($this->output);
        // $this->bans->execute($this->output);
        // $this->reports->execute($this->output);
        // $this->postMentionsUser->execute($this->output);

        $this->validation->execute($this->output);
    }

    protected function checkRequiredExtension()
    {
        $requiredExtensions = [
            'flarum-bbcode',
            'flarum-emoji',
            'flarum-mentions',
            'flarum-nicknames',
            'flarum-sticky',
            'flarum-subscriptions',
            'flarum-tags',
            'flarum-suspend',
            'flarum-lock',
            'migratetoflarum-old-passwords',
            'fof-polls',
            'fof-user-bio'

        ];
        foreach ($requiredExtensions as $requiredExtension) {
            if (!$this->extensionManager->isEnabled($requiredExtension)) {
                $this->error($requiredExtension . ' extension needs to be enabled');
                exit;
            }
        }
    }

    protected function getFluxBBDBConnection($fluxBBDIR)
    {
        if (file_exists($fluxBBDIR . 'config.php')) {
            include($fluxBBDIR . 'config.php');

            $this->DBmanager->addConnection([
                'driver' => 'mysql',
                'host' => $db_host,
                'port' => '3306',
                'database' => $db_name,
                'username' => $db_username,
                'password' => $db_password,
                'prefix' => $db_prefix,
                'strict' => true,
                'engine' => null,
                'charset' => 'utf8mb4',
            ], 'fluxbb');
        } else {
            $this->error($fluxBBDIR . 'config.php do not exist.');
            exit;
        };
    }

    protected function checkFluxBBAvatarDir($fluxBBDIR)
    {
        $this->avatarsDir = $fluxBBDIR . 'img/avatars/';
        if (!is_dir($this->avatarsDir)) {
            $this->error($this->avatarsDir . ' : not exist or is not a directory. Please check.');
            exit;
        }
    }
}
