<?php

namespace ErnestDefoe\Testimonials\Notification;

/** Told to the author: their testimonial was declined, with the reason (on the testimonial). */
class DeclinedBlueprint extends TestimonialBlueprint
{
    protected static function key(): string
    {
        return 'declined';
    }
}
