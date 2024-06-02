<?php

namespace ArchLinux\ImportFluxBB\Importer;

use Flarum\Formatter\Formatter;
use Flarum\Foundation\ContainerUtil;
use Flarum\Foundation\Paths;
use Flarum\Mentions\ConfigureMentions;
use Flarum\Post\CommentPost;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Filesystem\Filesystem;
use s9e\TextFormatter\Configurator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Posts
{
    private Manager $database;
    private Formatter $formatter;
    protected Container $container;

    public function __construct(Manager $database, Container $container)
    {
        $this->database = $database;
        $this->container = $container;
    }

    public function execute(OutputInterface $output)
    {
        $this->createFormater();

        $output->writeln('Importing posts...');

        $arr_topic_id = $this->database->connection()->table('discussions')->pluck('id')->toArray();

        $posts = $this->database->connection('fluxbb')
            ->table('posts')
            ->select(
                [
                    'id',
                    'poster',
                    'poster_id',
                    'poster_ip',
                    'poster_email',
                    'message',
                    'hide_smilies',
                    'posted',
                    'edited',
                    'edited_by',
                    'topic_id'
                ]
            )
            ->orderBy('topic_id')
            ->orderBy('id')
            ->whereIn('topic_id', $arr_topic_id)
            ->get()
            ->all();

        $progressBar = new ProgressBar($output, count($posts));

        $this->database->connection()->statement('SET FOREIGN_KEY_CHECKS=0');
        $lastTopicId = 0;
        $currentPostNumber = 0;
        foreach ($posts as $post) {
            if ($lastTopicId !== $post->topic_id) {
                $currentPostNumber = 0;
            }
            $currentPostNumber++;
            $this->database
                ->table('posts')
                ->insert(
                    [
                        'id' => $post->id,
                        'discussion_id' => $post->topic_id,
                        'number' => $currentPostNumber,
                        'created_at' => (new \DateTime())->setTimestamp($post->posted),
                        'user_id' => $post->poster_id > 1 ? $post->poster_id : null,
                        'type' => 'comment',
                        'content' => $this->convertPostContent($post),
                        'edited_at' => $post->edited ? (new \DateTime())->setTimestamp($post->edited) : null,
                        'edited_user_id' => $post->edited_by ? $this->getUserByName($post->edited_by) : null,
                        'hidden_at' => null,
                        'hidden_user_id' => null,
                        'ip_address' => $post->poster_ip,
                        'is_private' => 0,
                        'is_approved' => 1
                    ]
                );
            $lastTopicId = $post->topic_id;
            $progressBar->advance();
        }
        $this->database->connection()->statement('SET FOREIGN_KEY_CHECKS=1');
        $progressBar->finish();

        $output->writeln('');
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

    private function convertPostContent(object $post): string
    {
        $content = $this->replaceUnsupportedBBCode($post->message);
        $return = $this->formatter->parse(
            $content //,
            // CommentPost::reply($post->topic_id, $content, $post->poster_id, $post->poster_ip)
        );
        return $return;
    }

    private function replaceUnsupportedBBCode(string $text): string
    {
        $replacements = [
            '#\[h\](.+?)\[/h\]#i' => '[B]$1[/B]',
            '#\[em\](.+?)\[/em\]#i' => '[I]$1[/I]',
            '#\[ins\](.+?)\[/ins\]#i' => '[I]$1[/I]',

            // FluxBB uses a different syntax
            '#\[img=(.+?)\](.+?)\[/img\]#i' => '[IMG ALT=$1]$2[/IMG]',

            // '#<a href="thread\.php\?[^"]*thread=([0-9]+)[^"]*">\[url\].+?\[/url\]</a>#' => '[URL]https://bbs.archlinux.de/viewtopic.php?id=$1[/URL]',
            // '#<a href="thread\.php\?[^"]*thread=([0-9]+)[^"]*">(.+?)</a>#' => '[URL=https://bbs.archlinux.de/viewtopic.php?id=$1]$2[/URL]',
            //
            // '#<a href="showArticle\.php\?link=(.+?)">(.+?)</a>#' => '[URL=https://wiki.archlinux.de/title/$1]$2[/URL]',
            // '#<a href="packages\.php.*?">(.+?)</a>#' => '[URL=https://www.archlinux.de/packages]$1[/URL]',

            // '#<a href="\?page=Postings;id=20;thread=([0-9]+)" class="link">(.+?)</a>#s' => '[URL=https://bbs.archlinux.de/viewtopic.php?id=$1]$2[/URL]',

            '#<a href="\[url\](.+?)\[/url\]"?[^>]*>.+?</a>#s' => '[URL]$1[/URL]',

            '#<a href="?id=20;page=GetAttachment;file=1485" rel="nofollow"><img src="?id=20;page=GetAttachmentThumb;file=1485" alt="screenshot2.png" title="screenshot2.png" class="image" /></a>#' => '[URL]$1[/URL]',

            // '#\[url=(http://forum\.archlinux\.de[^\]]*)\]http://www\.laber\-land\.de[^\[]*\[/url\]#' => '[URL]$1[/URL]',

            '#<a href="(.+?)">\[url\].+?\[/url\](\.\.\.)?</a>#' => '[URL]$1[/URL]',

            '#<a href="([^"]+)">(.+?)</a>#' => '[URL=$1]$2[/URL]',


            // '#\[topic\](.+?)\[/topic\]#i' => '[URL]https://bbs.archlinux.de/viewtopic.php?id=$1[/URL]',
            // '#\[post\](.+?)\[/post\]#i' => '[URL]https://bbs.archlinux.de/viewtopic.php?pid=$1#p$1[/URL]',
            // '#\[forum\](.+?)\[/forum\]#i' => '[URL]https://bbs.archlinux.de/viewforum.php?id=$1[/URL]',
            // '#\[user\](.+?)\[/user\]#i' => '[URL]https://bbs.archlinux.de/profile.php?id=$1[/URL]',

            // '#\[topic=([0-9]+)\](.+?)\[/topic\]#i' => '[URL=https://bbs.archlinux.de/viewtopic.php?id=$1]$2[/URL]',
            // '#\[post=([0-9]+)\](.+?)\[/post\]#i' => '[URL=https://bbs.archlinux.de/viewtopic.php?pid=$1#p$]$2[/URL]',
            // '#\[forum=([0-9]+)\](.+?)\[/forum\]#i' => '[URL=https://bbs.archlinux.de/viewforum.php?id=$1]$2[/URL]',
            // '#\[user=([0-9]+)\](.+?)\[/user\]#i' => '[URL=https://bbs.archlinux.de/profile.php?id=$1]$2[/URL]'
        ];

        return preg_replace(array_keys($replacements), array_values($replacements), $text);
    }

    protected function createFormater()
    {
        $cacheDirectory = $this->container[Paths::class]->storage . '/cache/import-formatter';
        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory);
        }
        $repository = new Repository(new FileStore(new Filesystem, $cacheDirectory));
        $formatter = new Formatter($repository, $cacheDirectory);

        $formatter->addConfigurationCallback(
            function (Configurator $config) {
                // BBCode extension
                $config->BBCodes->addFromRepository('B');
                $config->BBCodes->addFromRepository('I');
                $config->BBCodes->addFromRepository('U');
                $config->BBCodes->addFromRepository('S');
                $config->BBCodes->addFromRepository('URL');
                $config->BBCodes->addFromRepository('IMG');
                $config->BBCodes->addFromRepository('EMAIL');
                $config->BBCodes->addFromRepository('CODE');
                $config->BBCodes->addFromRepository('QUOTE');
                $config->BBCodes->addFromRepository('LIST');
                $config->BBCodes->addFromRepository('DEL');
                $config->BBCodes->addFromRepository('COLOR');
                //                $config->BBCodes->addFromRepository('CENTER');
                //                $config->BBCodes->addFromRepository('SIZE');
                $config->BBCodes->addFromRepository('*');

                // Emoji extension
                $config->Emoticons->add(':)', '🙂');
                $config->Emoticons->add(':D', '😃');
                $config->Emoticons->add(':P', '😛');
                $config->Emoticons->add(':(', '🙁');
                $config->Emoticons->add(':|', '😐');
                $config->Emoticons->add(';)', '😉');
                $config->Emoticons->add(':\'(', '😢');
                $config->Emoticons->add(':O', '😮');
                $config->Emoticons->add('>:(', '😡');

                // Reduce false positive in e.g. URLs and :/
                $config->Emoticons->notAfter = '\b';

                // Emoticons in addition to https://github.com/flarum/emoji/blob/master/extend.php#L20
                $emoticons = [
                    // Old LL
                    // See https://github.com/pierres/ll/blob/origin/modules/Markup.php#L44
                    ';D' => '😁',
                    '::)' => '🙄',
                    ':-\\' => '😕',
                    ':-X' => '🤐',
                    ':-x' => '🤐',
                    ':-[' => '😅',
                    '8)' => '😎',
                    '???' => '😕',
                    ':\'(' => '😢',
                    '\'(' => '😢',

                    // LL
                    // See https://github.com/pierres/ll/blob/master/modules/Markup.php#L38
                    '0:-)' => '😇',
                    ':-*)' => '😳',
                    ':-*' => '😘',
                    'xD' => '😆',
                    ':-|' => '😐',
                    ':-P' => '😛',
                    ':-(' => '🙁',
                    ':-D' => '😃',
                    ':-)' => '🙂',
                    ':-0' => '😮',
                    ':-/' => '😕',
                    ';-)' => '😉',

                    // FluxBB
                    // See https://github.com/fluxbb/fluxbb/blob/master/include/parser.php#L42
                    '=)' => '🙂',
                    '=|' => '😐',
                    '=(' => '🙁',
                    '=D' => '😃',
                    ':o' => '😮',
                    ':/' => '😕',
                    ':p' => '😛',
                    ':lol:' => '😂',
                    ':mad:' => '😡',
                    ':rolleyes:' => '🙄',
                    ':cool:' => '😎',
                    //TODO
                    //ADD Other emoticons?
                ];

                foreach ($emoticons as $code => $emoji) {
                    $config->Emoticons->add($code, $emoji);
                }

                $config->urlConfig->allowScheme('ftp');
            }
        );

        // TODO I must to comment this line otherwise I will get:
        // Fatal error: Uncaught TypeError: Flarum\Mentions\ConfigureMentions::addUserId(): Argument #2 ($mentions) must be of type array, null given
        //
        // $formatter->addConfigurationCallback(ContainerUtil::wrapCallback(ConfigureMentions::class, $this->container));

        $this->formatter = $formatter;
    }
}
