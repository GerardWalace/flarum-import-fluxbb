<?php

namespace ArchLinux\ImportFluxBB\Importer;

use Illuminate\Database\Capsule\Manager;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class PostMentionsUser
{
    private Manager $database;

    public function __construct(Manager $database)
    {
        $this->database = $database;
    }

    public function execute(OutputInterface $output)
    {
        $output->writeln('Importing posts mention users...');

        $posts = $this->database->connection()
            ->table('posts')
            ->select(['id', 'content'])
            ->where('content', 'LIKE', '%<USERMENTION %')
            ->orderBy('id')
            ->get()
            ->all();

        $progressBar = new ProgressBar($output, count($posts));

        foreach ($posts as $post) {
            preg_match_all(
                '#<USERMENTION displayname=".+?" id="([0-9]+)">@.+?</USERMENTION>#',
                $post->content,
                $matches
            );
            foreach (array_unique($matches[1]) as $match) {
                $this->database->connection()
                    ->table('post_mentions_user')
                    ->insert(
                        [
                            'post_id' => $post->id,
                            'mentions_user_id' => $match
                        ]
                    );
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $output->writeln('');
    }
}
