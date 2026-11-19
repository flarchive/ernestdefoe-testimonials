<?php

namespace ErnestDefoe\Testimonials\Notification;

/** Told to the author: their testimonial is live. */
class ApprovedBlueprint extends TestimonialBlueprint
{
    protected static function key(): string
    {
        return 'approved';
    }
}
