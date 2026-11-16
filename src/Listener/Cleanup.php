<?php

namespace ErnestDefoe\Testimonials\Listener;

use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Rating;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Post\Post;
use Flarum\User\Event\Deleting as UserDeleting;

/**
 * What happens to testimonials when what they point at goes away. The foreign keys cover the
 * author where the database enforces them (SQLite may not), so it's done here too.
 */
class Cleanup
{
    public function __construct(
        private readonly Moderation $moderation,
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

    /** A deleted tag or download takes the testimonials about it. */
    public function subject(string $type, int $id): void
    {
        $ids = Testimonial::query()->where('subject_type', $type)->where('subject_id', $id)->pluck('id')->all();
        if ($ids) {
            Rating::deleteWithTestimonials($ids);
            $this->moderation->changed();
        }
    }

    /**
     * A deleted tag (from the `eloquent.deleted` event, which passes the model, or the model in a
     * payload array depending on how it was dispatched).
     */
    public function tag(mixed $event, mixed $models = null): void
    {
        $this->subject('tag', (int) self::model($event, $models)->id);
    }

    /** A deleted Depot download. */
    public function depotFile(mixed $event, mixed $models = null): void
    {
        $this->subject('depot', (int) self::model($event, $models)->id);
    }

    /** Post and discussion deletions: the payload doesn't matter, the orphans are found afresh. */
    public function postsGone(): void
    {
        $this->posts();
    }

    private static function model(mixed $event, mixed $models): object
    {
        return is_object($event) ? $event : $models[0];
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
