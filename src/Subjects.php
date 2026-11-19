<?php

namespace ErnestDefoe\Testimonials;

use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * What each testimonial is about, as a label and a link: one query per kind of subject for a
 * whole page of testimonials, never one per row. The label is only shown where the row itself
 * is visible, and a row is only visible where its subject is (Scope\Visibility).
 */
class Subjects
{
    /** Where a row keeps its looked-up subject (a relation slot: never saved). */
    private const LOADED = 'subjectLabel';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Extensions $extensions,
        private readonly UrlGenerator $url,
    ) {
    }

    /**
     * Looks up the subjects of these rows (those not looked up yet) and keeps each on its row, so
     * the answer lives exactly as long as the row: one request, never a stale label from an earlier one.
     *
     * @param iterable<Testimonial> $rows
     */
    public function prime(iterable $rows): void
    {
        $todo = [];
        $ids = ['tag' => [], 'depot' => [], 'post' => []];
        foreach ($rows as $row) {
            if ($row->relationLoaded(self::LOADED)) {
                continue;
            }
            $todo[] = $row;
            if ($row->subject_id && isset($ids[$row->subject_type])) {
                $ids[$row->subject_type][] = (int) $row->subject_id;
            }
        }

        $found = [];
        if ($ids['tag'] && $this->extensions->tags()) {
            foreach ($this->db->table('tags')->whereIn('id', array_unique($ids['tag']))->get(['id', 'name', 'slug']) as $tag) {
                $found['tag:'.$tag->id] = ['label' => (string) $tag->name, 'url' => $this->url->to('forum')->route('tag', ['slug' => $tag->slug])];
            }
        }
        if ($ids['depot'] && $this->extensions->depot()) {
            foreach (Depot\Files::labels(array_unique($ids['depot'])) as $id => $file) {
                $found['depot:'.$id] = ['label' => $file['title'], 'url' => $this->url->to('forum')->route('depot.file', ['slug' => $file['slug']])];
            }
        }
        if ($ids['post']) {
            $posts = $this->db->table('posts')
                ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
                ->whereIn('posts.id', array_unique($ids['post']))
                ->get(['posts.id', 'posts.number', 'posts.discussion_id', 'discussions.title', 'discussions.slug']);
            foreach ($posts as $post) {
                $found['post:'.$post->id] = [
                    'label' => (string) $post->title,
                    'url' => $this->url->to('forum')->route('discussion.near', ['id' => $post->discussion_id.($post->slug ? '-'.$post->slug : ''), 'near' => $post->number]),
                ];
            }
        }

        // Not found (deleted, or its extension switched off) is kept too, as null.
        foreach ($todo as $row) {
            $row->setRelation(self::LOADED, $found[$row->subject_type.':'.$row->subject_id] ?? null);
        }
    }

    /** A tag's or download's id from its slug (what admins type in a widget's settings), or null. */
    public function idForSlug(string $type, string $slug): ?int
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $id = match (true) {
            $type === 'tag' && $this->extensions->tags() => $this->db->table('tags')->where('slug', $slug)->value('id'),
            $type === 'depot' && $this->extensions->depot() => Depot\Files::idForSlug($slug),
            default => null,
        };

        return $id === null ? null : (int) $id;
    }

    /**
     * Downloads the form's picker offers for $term (none without Depot, or under two letters).
     *
     * @return list<array{id: int, title: string}>
     */
    public function depotMatches(User $actor, string $term): array
    {
        $term = mb_substr(trim($term), 0, 80);

        return $this->extensions->depot() && mb_strlen($term) >= 2 ? Depot\Files::search($actor, $term) : [];
    }

    /** @return array{type: string, id: int|null, label: string|null, url: string|null} */
    public function of(Testimonial $row): array
    {
        $found = null;
        if ($row->subject_type !== 'community' && $row->subject_id) {
            if (! $row->relationLoaded(self::LOADED)) {
                $this->prime([$row]);
            }
            $found = $row->getRelation(self::LOADED);
        }

        return [
            'type' => $row->subject_type,
            'id' => $row->subject_id,
            'label' => $found['label'] ?? null,
            'url' => $found['url'] ?? null,
        ];
    }
}
