<?php

namespace ArchLinux\ImportFluxBB\Importer;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Topics
{
    private Manager $database;

    public function __construct(Manager $database)
    {
        $this->database = $database;
    }

    public function execute(OutputInterface $output)
    {
        $output->writeln('Importing topics...');

        // Necessite de créer une colonne avec la requete suivante
        // UPDATE xpunbb_topics as t
        // inner join xpunbb_posts as p on p.topic_id = t.id
        // set t.first_post_id = p.id
        // update xpunbb_topics as t1
        // join (select t.id, min(p.id) as minvalue from xpunbb_topics as t inner join xpunbb_posts as p on p.topic_id = t.id group by t.id) as t2
        // on t1.id = t2.id
        // set t1.first_post_id = t2.minvalue;

        $topics = $this->database->connection('fluxbb')
            ->table('topics')
            ->select(
                [
                    'id',
                    'poster',
                    'subject',
                    'posted',
                    'first_post_id',
                    'last_post',
                    'last_post_id',
                    'last_poster',
                    'num_views',
                    'num_replies',
                    'closed',
                    'sticky',
                    'moved_to',
                    'forum_id'
                ]
            )
            ->whereNull('moved_to')
            ->orderBy('id')
            ->get()
            ->all();

        $progressBar = new ProgressBar($output, count($topics));

        $this->database->connection()->statement('SET FOREIGN_KEY_CHECKS=0');
        // Not needed for TheRoyals.it
        // $solvedTagId = $this->createSolvedTag();

        foreach ($topics as $topic) {
            $numberOfPosts = $topic->num_replies + 1;
            $tagIds = [$this->getParentTagId($topic->forum_id), $topic->forum_id];

            // if ($this->replaceSolvedHintByTag($topic->subject)) {
            // $tagIds[] = $solvedTagId;
            // }

            $this->database
                ->table('discussions')
                ->insert(
                    [
                        'id' => $topic->id,
                        'title' => mb_convert_encoding($topic->subject, 'UTF-8', 'HTML-ENTITIES'),
                        'comment_count' => $numberOfPosts,
                        'participant_count' => $this->getParticipantCountByTopic($topic->id),
                        'post_number_index' => $numberOfPosts,
                        'created_at' => (new \DateTime())->setTimestamp($topic->posted),
                        'user_id' => $this->getUserByPost($topic->first_post_id),
                        'first_post_id' => $topic->first_post_id,
                        'last_posted_at' => (new \DateTime())->setTimestamp($topic->last_post),
                        'last_posted_user_id' => $this->getUserByPost($topic->last_post_id),
                        'last_post_id' => $topic->last_post_id,
                        'last_post_number' => $numberOfPosts,
                        'hidden_at' => null,
                        'hidden_user_id' => null,
                        'slug' => Str::slug(preg_replace('/\.+/', '-', $topic->subject), '-', 'de'),
                        'is_private' => 0,
                        'is_approved' => 1,
                        'is_locked' => $topic->closed,
                        'is_sticky' => $topic->sticky
                    ]
                );

            foreach ($tagIds as $tagId) {
                $this->database
                    ->table('discussion_tag')
                    ->insert(
                        [
                            'discussion_id' => $topic->id,
                            'tag_id' => $tagId,
                        ]
                    );
            }

            $progressBar->advance();
        }
        $this->database->connection()->statement('SET FOREIGN_KEY_CHECKS=1');
        $progressBar->finish();

        $output->writeln('');
    }

    private function getUserByPost(int $postId): ?int
    {
        $post = $this->database->connection('fluxbb')
            ->table('posts')
            ->select(['poster', 'poster_id'])
            ->where('id', '=', $postId)
            ->get()
            ->first();

        if ($post->poster_id > 1) {
            return $post->poster_id;
        } else {
            return $this->getUserByName($post->poster);
        }
    }

    private function getUserByName(string $nickname): ?int
    {
        $user = $this->database->connection('fluxbb')
            ->table('users')
            ->select(['id'])
            ->where('username', '=', $nickname)
            ->get()
            ->first();

        return $user->id ?? null;
    }

    private function getParticipantCountByTopic(int $topicId): int
    {
        $participants = $this->database->connection('fluxbb')
            ->table('posts')
            ->select('poster')
            ->where('topic_id', '=', $topicId)
            ->groupBy('poster')
            ->get()
            ->all();
        return count($participants);
    }

    private function getParentTagId(int $tagId): int
    {
        $user = $this->database->connection('fluxbb')
            ->table('forums')
            ->select(['cat_id'])
            ->where('id', '=', $tagId)
            ->get()
            ->first();

        return $user->cat_id + 50;  // Suggested Fix https://discuss.flarum.org/d/3867-fluxbb-to-flarum-migration-tool/11
    }

    private function createSolvedTag(): int
    {
        return $this->database
            ->table('tags')
            ->insertGetId(
                [
                    'name' => 'gelöst',
                    'slug' => 'geloest',
                    'description' => 'Fragen die beantwortet und Themen die gelöst wurden',
                    'color' => '#2e8b57',
                    'is_hidden' => 1,
                    'icon' => 'fas fa-check-square',
                ]
            );
    }

    private function replaceSolvedHintByTag(string &$title): bool
    {
        $solvedHint = '(gel(ö|oe)(s|ss|ß)t|(re)?solved|erledigt|done|geschlossen)';
        $count = 0;
        $title = preg_replace(
            [
                '/^\s*(\[|\()\s*' . $solvedHint . '\s*(\]|\))\s*/i',
                '/\s*(\[|\()\s*' . $solvedHint . '\s*(\]|\))\s*$/i',
                '/^\s*' . $solvedHint . ':\s*/i'
            ],
            '',
            $title,
            -1,
            $count
        );
        return $count > 0;
    }
}
