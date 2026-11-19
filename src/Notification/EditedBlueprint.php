<?php

namespace ErnestDefoe\Testimonials\Notification;

/** Told to the author: a moderator fixed the wording; they can take it down if they disagree. */
class EditedBlueprint extends TestimonialBlueprint
{
    protected static function key(): string
    {
        return 'edited';
    }
}
