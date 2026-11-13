<?php

namespace ErnestDefoe\Testimonials\Event;

use ErnestDefoe\Testimonials\Testimonial;

/**
 * A live testimonial stopped being live: declined, marked spam, taken down, deleted, or edited
 * by its author (it goes back for review). Approved fires again if it comes back.
 */
class Withdrawn
{
    public function __construct(public readonly Testimonial $testimonial)
    {
    }
}
