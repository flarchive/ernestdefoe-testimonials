<?php

namespace ErnestDefoe\Testimonials;

use Carbon\Carbon;
use Flarum\User\User;

/**
 * The "share your experience" card: off unless the admin turns it on; then shown to members who
 * may write one, have posted and stayed long enough, and haven't written one yet (one EXISTS
 * query, only once the cheap checks pass). Dismissing it is the browser's business.
 */
class Nudge
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Access $access,
    ) {
    }

    public function shows(User $actor): bool
    {
        if ($actor->isGuest() || ! $this->settings->bool('nudge', false) || ! $this->access->canCreate($actor)) {
            return false;
        }
        if ((int) $actor->comment_count < $this->settings->int('nudge_posts', 25)) {
            return false;
        }
        $days = $this->settings->int('nudge_days', 30);
        if ($days > 0 && (! $actor->joined_at || $actor->joined_at->gt(Carbon::now()->subDays($days)))) {
            return false;
        }

        return ! Testimonial::query()->where('user_id', $actor->id)->where('source', 'native')->exists();
    }
}
