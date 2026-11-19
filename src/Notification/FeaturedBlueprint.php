<?php

namespace ErnestDefoe\Testimonials\Notification;

/** Told to the author: their testimonial is featured. */
class FeaturedBlueprint extends TestimonialBlueprint
{
    protected static function key(): string
    {
        return 'featured';
    }
}
