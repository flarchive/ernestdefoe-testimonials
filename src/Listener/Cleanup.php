<?php

namespace ErnestDefoe\Testimonials\Listener;

use ErnestDefoe\Testimonials\Event\Withdrawn;
use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Rating;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Post\Post;
use Flarum\User\Event\Deleting as UserDeleting;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * What happens to testimonials when what they point at goes away. The author's foreign key would
 * cascade too; doing it here as well means it doesn't depend on how the database was set up.
 */
class Cleanup
{
    public function __construct(
        private readonly Moderation $moderation,
        private readonly Dispatcher $events,
    ) {
    }

    /** A deleted member's testimonials go with them. */
    public function user(UserDeleting $event): void
    {
        $ids = Testimonial::query()->where('user_id', $event->user->id)->pluck('id')->all();
        if ($ids) {
            Rating::deleteWithTestimonials($ids);
            $this->moderation->changed();
        }
    }

    /**
     * A deleted tag or download takes the testimonials about it. The live ones fire Withdrawn
     * first, as any other way of taking one down does (Renown takes back the XP).
     */
    public function subject(string $type, int $id): void
    {
        $rows = Testimonial::query()->where('subject_type', $type)->where('subject_id', $id)->get();
        if ($rows->isEmpty()) {
            return;
        }
        foreach ($rows->where('status', 'approved') as $row) {
            $this->events->dispatch(new Withdrawn($row));
        }
        Rating::deleteWithTestimonials($rows->modelKeys());
        $this->moderation->changed();
    }

    /** A deleted tag (`eloquent.deleted`, which passes the model). */
    public function tag(object $tag): void
    {
        $this->subject('tag', (int) $tag->id);
    }

    /** A deleted Depot download (`eloquent.deleted`, which passes the model). */
    public function depotFile(object $file): void
    {
        $this->subject('depot', (int) $file->id);
    }

    /** Post and discussion deletions: the payload doesn't matter, the orphans are found afresh. */
    public function postsGone(): void
    {
        $this->posts();
    }

    /**
     * Testimonials made from a post that's gone (deleted, or its discussion deleted, which removes
     * posts without an event each) stay, as testimonials about the community.
     */
    public function posts(): void
    {
        $orphans = Testimonial::query()->where('subject_type', 'post')
            // Every post row, whatever its type (the model's scopes would hide unregistered types).
            ->whereNotIn('subject_id', Post::query()->withoutGlobalScopes()->select('id'))
            ->update(['subject_type' => 'community', 'subject_id' => null]);
        if ($orphans) {
            $this->moderation->changed();
        }
    }
}
