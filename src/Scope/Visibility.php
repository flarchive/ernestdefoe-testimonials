<?php

namespace ErnestDefoe\Testimonials\Scope;

use ErnestDefoe\Testimonials\Extensions;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees a testimonial (registered with Extend\ModelVisibility, so notification lists follow it too):
 *
 * - moderators: all of them;
 * - its author: their own, at any stage (so they can follow it and take it down);
 * - everyone with `testimonials.view`: live ones (Testimonial::onlyLive) about something they may see.
 *   A testimonial about a tag follows the tag's visibility, about a post the post's, about a
 *   download Depot's. With Tags or Depot switched off, those testimonials show to no one but
 *   their author and moderators.
 */
class Visibility
{
    public function __construct(private readonly Extensions $extensions)
    {
    }

    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('testimonials.moderate')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor) {
            if ($actor->hasPermission('testimonials.view')) {
                $q->where(function (Builder $live) use ($actor) {
                    Testimonial::onlyLive($live);
                    $live->where(function (Builder $subject) use ($actor) {
                        $subject->where('testimonials.subject_type', 'community');
                        $subject->orWhere(fn (Builder $s) => $s->where('testimonials.subject_type', 'post')
                            ->whereIn('testimonials.subject_id', Post::whereVisibleTo($actor)->select('posts.id')));
                        if ($this->extensions->tags()) {
                            $subject->orWhere(fn (Builder $s) => $s->where('testimonials.subject_type', 'tag')
                                ->whereIn('testimonials.subject_id', \Flarum\Tags\Tag::whereVisibleTo($actor)->select('tags.id')));
                        }
                        if ($this->extensions->depot()) {
                            $subject->orWhere(fn (Builder $s) => $s->where('testimonials.subject_type', 'depot')
                                ->whereIn('testimonials.subject_id', \ErnestDefoe\Testimonials\Depot\Files::visibleIds($actor)));
                        }
                    });
                });
            } else {
                $q->whereRaw('1 = 0');
            }

            if ($actor->exists) {
                $q->orWhere('testimonials.user_id', $actor->id);
            }
        });
    }
}
