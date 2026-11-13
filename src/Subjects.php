<?php

namespace ErnestDefoe\Testimonials;

use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;

/**
 * What each testimonial is about, as a label and a link: one query per kind of subject for a
 * whole page of testimonials, never one per row. The label is only shown where the row itself
 * is visible, and a row is only visible where its subject is (Scope\Visibility).
 */
class Subjects
{
    /** @var array<string, array{label: string, url: string|null}|null> keyed "type:id" */
    private array $memo = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Extensions $extensions,
        private readonly UrlGenerator $url,
    ) {
    }

    /** @param iterable<Testimonial> $rows */
    public function prime(iterable $rows): void
    {
        $ids = ['tag' => [], 'depot' => [], 'post' => []];
        foreach ($rows as $row) {
            if ($row->subject_id && isset($ids[$row->subject_type]) && ! array_key_exists($row->subject_type.':'.$row->subject_id, $this->memo)) {
                $ids[$row->subject_type][] = (int) $row->subject_id;
            }
        }

        if ($ids['tag'] && $this->extensions->tags()) {
            foreach ($this->db->table('tags')->whereIn('id', array_unique($ids['tag']))->get(['id', 'name', 'slug']) as $tag) {
                $this->memo['tag:'.$tag->id] = ['label' => (string) $tag->name, 'url' => $this->url->to('forum')->route('tag', ['slug' => $tag->slug])];
            }
        }
        if ($ids['depot'] && $this->extensions->depot()) {
            foreach ($this->db->table('depot_files')->whereIn('id', array_unique($ids['depot']))->get(['id', 'title', 'slug']) as $file) {
                $this->memo['depot:'.$file->id] = ['label' => (string) $file->title, 'url' => $this->url->to('forum')->path('downloads/f/'.$file->slug)];
            }
        }
        if ($ids['post']) {
            $posts = $this->db->table('posts')
                ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
                ->whereIn('posts.id', array_unique($ids['post']))
                ->get(['posts.id', 'posts.number', 'posts.discussion_id', 'discussions.title', 'discussions.slug']);
            foreach ($posts as $post) {
                $this->memo['post:'.$post->id] = [
                    'label' => (string) $post->title,
                    'url' => $this->url->to('forum')->route('discussion.near', ['id' => $post->discussion_id.($post->slug ? '-'.$post->slug : ''), 'near' => $post->number]),
                ];
            }
        }

        // Asked and not found (deleted, or its extension switched off): remember that too.
        foreach ($ids as $type => $list) {
            foreach ($list as $id) {
                $this->memo[$type.':'.$id] ??= null;
            }
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
            $type === 'depot' && $this->extensions->depot() => $this->db->table('depot_files')->where('slug', $slug)->value('id'),
            default => null,
        };

        return $id === null ? null : (int) $id;
    }

    /** @return array{type: string, id: int|null, label: string|null, url: string|null} */
    public function of(Testimonial $row): array
    {
        $found = null;
        if ($row->subject_type !== 'community' && $row->subject_id) {
            $key = $row->subject_type.':'.$row->subject_id;
            if (! array_key_exists($key, $this->memo)) {
                $this->prime([$row]);
            }
            $found = $this->memo[$key] ?? null;
        }

        return [
            'type' => $row->subject_type,
            'id' => $row->subject_id,
            'label' => $found['label'] ?? null,
            'url' => $found['url'] ?? null,
        ];
    }
}
