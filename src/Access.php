<?php

namespace ErnestDefoe\Testimonials;

use Carbon\Carbon;
use Flarum\User\User;

/**
 * Who may write, edit and take down. No queries: everything here is on the user row or in
 * their (already loaded) groups, so the forum payload can ask it on every page.
 */
class Access
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TrustLevel $trust,
    ) {
    }

    public function canCreate(User $actor): bool
    {
        return $this->createRefusal($actor) === null;
    }

    /** Why this member can't write one yet, or null. */
    public function createRefusal(User $actor): ?string
    {
        if ($actor->isGuest() || ! $actor->hasPermission('testimonials.create')) {
            return 'permission';
        }
        if ($actor->hasPermission('testimonials.moderate')) {
            return null;
        }
        if ((int) $actor->comment_count < $this->settings->int('min_posts', 0)) {
            return 'min_posts';
        }
        $days = $this->settings->int('min_days', 0);
        if ($days > 0 && (! $actor->joined_at || $actor->joined_at->gt(Carbon::now()->subDays($days)))) {
            return 'min_days';
        }
        $level = $this->settings->int('min_trust_level', 0);
        if ($level > 0 && ($have = $this->trust->of($actor)) !== null && $have < $level) {
            return 'min_trust_level';
        }

        return null;
    }

    public function autoApproves(User $actor): bool
    {
        return $actor->hasPermission('testimonials.moderate') || $actor->hasPermission('testimonials.autoApprove');
    }

    /** The author may change what they wrote (not a mirrored Depot review: that's edited on Depot). */
    public function canEdit(Testimonial $row, User $actor): bool
    {
        return Presenter::isAuthor($row, $actor) && $row->source === 'native' && ! in_array($row->status, ['spam', 'removed'], true);
    }

    /** Moderators may fix the wording of a native testimonial. */
    public function canEditWording(Testimonial $row, User $actor): bool
    {
        return Presenter::canModerate($actor) && $row->source === 'native';
    }

    public function canRemove(Testimonial $row, User $actor): bool
    {
        return $row->source === 'native' && $row->status !== 'removed'
            && (Presenter::isAuthor($row, $actor) || Presenter::canModerate($actor));
    }

    public function canRestore(Testimonial $row, User $actor): bool
    {
        return $row->status === 'removed' && (Presenter::isAuthor($row, $actor) || Presenter::canModerate($actor));
    }

    /** A mirrored Depot review: its author may take it out of the widgets, or put it back. */
    public function canHide(Testimonial $row, User $actor): bool
    {
        return $row->source === 'depot' && (Presenter::isAuthor($row, $actor) || Presenter::canModerate($actor));
    }
}
