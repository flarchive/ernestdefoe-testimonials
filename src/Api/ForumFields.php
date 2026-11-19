<?php

namespace ErnestDefoe\Testimonials\Api;

use ErnestDefoe\Testimonials\Access;
use ErnestDefoe\Testimonials\Extensions;
use ErnestDefoe\Testimonials\Nudge;
use ErnestDefoe\Testimonials\Settings;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/**
 * What the forum payload carries for Testimonials: what the viewer may do (their groups and user
 * row are already loaded: no queries), the queue count for moderators (one COUNT, only for them),
 * and the nudge (queries only when the admin turned it on and the member passed its thresholds).
 */
class ForumFields
{
    public function __construct(
        private readonly Access $access,
        private readonly Nudge $nudge,
        private readonly Settings $settings,
        private readonly Extensions $extensions,
    ) {
    }

    /** @return array<int, Schema\Attribute> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('testimonialsCanCreate')
                ->get(fn ($forum, Context $context) => $this->access->canCreate($context->getActor())),
            Schema\Str::make('testimonialsCreateRefusal')->nullable()
                ->visible(fn ($forum, Context $context) => ! $context->getActor()->isGuest())
                ->get(fn ($forum, Context $context) => $this->access->createRefusal($context->getActor())),
            Schema\Integer::make('testimonialsPending')
                ->visible(fn ($forum, Context $context) => $this->canModerate($context))
                ->get(fn () => Testimonial::query()->where('status', 'pending')->count()),
            Schema\Boolean::make('testimonialsNudge')
                ->visible(fn ($forum, Context $context) => ! $context->getActor()->isGuest() && $this->settings->bool('nudge'))
                ->get(fn ($forum, Context $context) => $this->nudge->shows($context->getActor())),
            Schema\Arr::make('testimonialsAspects')
                ->get(fn () => $this->settings->aspects()),
            Schema\Boolean::make('testimonialsDepot')
                ->get(fn () => $this->extensions->depot()),
            Schema\Boolean::make('testimonialsCanModerate')
                ->get(fn ($forum, Context $context) => $this->canModerate($context)),
        ];
    }

    private function canModerate(Context $context): bool
    {
        return $context->getActor()->hasPermission('testimonials.moderate');
    }
}
