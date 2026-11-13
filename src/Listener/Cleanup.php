<?php

namespace ErnestDefoe\Testimonials\Listener;

use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\User\Event\Deleting as UserDeleting;
use Illuminate\Database\ConnectionInterface;

/**
 * What happens to testimonials when what they point at goes away. The foreign keys cover the
 * author where the database enforces them (SQLite may not), so it's done here too.
 */
class Cleanup
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Moderation $moderation,
    ) {
    }

    /** A deleted member's testimonials go with them. */
    public function user(UserDeleting $event): void
    {
        $ids = Testimonial::query()->where('user_id', $event->user->id)->pluck('id')->all();
        if ($ids) {
            $this->db->table('testimonial_ratings')->whereIn('testimonial_id', $ids)->delete();
            Testimonial::query()->whereIn('id', $ids)->delete();
            $this->moderation->changed();
        }
    }

    /** A deleted tag or download takes the testimonials about it. */
    public function subject(string $type, int $id): void
    {
        $ids = Testimonial::query()->where('subject_type', $type)->where('subject_id', $id)->pluck('id')->all();
        if ($ids) {
            $this->db->table('testimonial_ratings')->whereIn('testimonial_id', $ids)->delete();
            Testimonial::query()->whereIn('id', $ids)->delete();
            $this->moderation->changed();
        }
    }

    /**
     * Testimonials made from a post that's gone (deleted, or its discussion deleted, which removes
     * posts without an event each) stay, as testimonials about the community.
     */
    public function posts(): void
    {
        $orphans = Testimonial::query()->where('subject_type', 'post')
            ->whereNotIn('subject_id', $this->db->table('posts')->select('id'))
            ->update(['subject_type' => 'community', 'subject_id' => null]);
        if ($orphans) {
            $this->moderation->changed();
        }
    }
}
