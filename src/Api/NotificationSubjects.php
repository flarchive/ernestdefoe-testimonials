<?php

namespace ErnestDefoe\Testimonials\Api;

use ErnestDefoe\Testimonials\Presenter;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Api\Context;
use Illuminate\Database\Eloquent\Collection;

/**
 * The notifications list: core loads each notification's subject but not what our serializer reads
 * from a testimonial (its author, aspect ratings and subject label). Loaded here for the whole page
 * at once, so a page of testimonial notifications costs the same few queries as one.
 */
class NotificationSubjects
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    /** @param array{models: iterable<\Flarum\Notification\Notification>} $results */
    public function __invoke(Context $context, array $results): void
    {
        $rows = new Collection();
        foreach ($results['models'] as $notification) {
            $subject = $notification->relationLoaded('subject') ? $notification->getRelation('subject') : null;
            if ($subject instanceof Testimonial) {
                $rows->push($subject);
            }
        }
        if ($rows->isEmpty()) {
            return;
        }
        $rows->loadMissing($this->presenter->relations($context->getActor()));
        $this->presenter->prime($rows);
    }
}
