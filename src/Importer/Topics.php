<?php

namespace ArchLinux\ImportFluxBB\Importer;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Topics
{
    private ConnectionInterface $database;
    private string $fluxBBDatabase;

    public function __construct(ConnectionInterface $database)
    {
        $this->database = $database;
    }

    public function execute(OutputInterface $output, string $fluxBBDatabase)
    {
        $this->fluxBBDatabase = $fluxBBDatabase;
        $output->writeln('Importing topics...');

        $topics = $this->database
            ->table($this->fluxBBDatabase . '.topics')
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
            ->where('moved_to', '=', null)
            ->orderBy('id')
            ->get()
            ->all();

        $progressBar = new ProgressBar($output, count($topics));

        $this->database->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($topics as $topic) {
            $numberOfPosts = $topic->num_replies + 1;
            $this->database
                ->table('discussions')
                ->insert(
                    [
                        'id' => $topic->id,
                        'title' => $topic->subject,
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
            $this->database
                ->table('discussion_tag')
                ->insert(
                    [
                        'discussion_id' => $topic->id,
                        'tag_id' => $this->getParentTagId($topic->forum_id),
                    ]
                );
            $this->database
                ->table('discussion_tag')
                ->insert(
                    [
                        'discussion_id' => $topic->id,
                        'tag_id' => $topic->forum_id,
                    ]
                );
            $progressBar->advance();
        }
        $this->database->statement('SET FOREIGN_KEY_CHECKS=1');
        $progressBar->finish();

        $output->writeln('');
    }

    private function getUserByPost(int $postId): ?int
    {
        $post = $this->database
            ->table($this->fluxBBDatabase . '.posts')
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
        $user = $this->database
            ->table($this->fluxBBDatabase . '.users')
            ->select(['id'])
            ->where('username', '=', $nickname)
            ->get()
            ->first();

        return $user->id ?? null;
    }

    private function getParticipantCountByTopic(int $topicId): int
    {
        $participants = $this->database
            ->table($this->fluxBBDatabase . '.posts')
            ->select('poster')
            ->where('topic_id', '=', $topicId)
            ->groupBy('poster')
            ->get()
            ->all();
        return count($participants);
    }

    private function getParentTagId(int $tagId): int
    {
        $user = $this->database
            ->table($this->fluxBBDatabase . '.forums')
            ->select(['cat_id'])
            ->where('id', '=', $tagId)
            ->get()
            ->first();

        return $user->cat_id;
    }
}