<?php

namespace ErnestDefoe\Testimonials\Event;

use ErnestDefoe\Testimonials\Testimonial;
use Flarum\User\User;

/**
 * A testimonial went live: approved, featured from the queue, restored, or written by someone
 * whose testimonials need no review. Renown gives XP on it (once per member and subject);
 * Withdrawn takes it back.
 */
class Approved
{
    public function __construct(public readonly Testimonial $testimonial, public readonly ?User $actor = null)
    {
    }
}
