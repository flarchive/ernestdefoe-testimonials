<?php

namespace ErnestDefoe\Testimonials;

use Carbon\Carbon;
use ErnestDefoe\Testimonials\Event\Approved;
use ErnestDefoe\Testimonials\Event\Withdrawn;
use ErnestDefoe\Testimonials\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The queue's actions and the author's take-down. Every change that can alter what guests see
 * bumps one cache version (Summary keys on it), and fires Approved/Withdrawn on the way in and
 * out of public view.
 */
class Moderation
{
    public const ACTIONS = ['approve', 'feature', 'unfeature', 'decline', 'spam', 'remove', 'restore', 'up', 'down'];

    public function __construct(
        private readonly Access $access,
        private readonly NotificationSyncer $notifications,
        private readonly Dispatcher $events,
        private readonly Cache $cache,
    ) {
    }

    /** @param array<string, mixed> $in */
    public function act(Testimonial $row, string $action, array $in, User $actor): void
    {
        $allowed = match ($action) {
            'remove' => $this->access->canRemove($row, $actor),
            'restore' => $this->access->canRestore($row, $actor),
            default => in_array($action, self::ACTIONS, true) && Presenter::canModerate($actor),
        };
        if (! $allowed) {
            throw new PermissionDeniedException();
        }

        $wasLive = $row->isLive();

        switch ($action) {
            case 'approve':
            case 'feature':
                if ($row->status === 'removed') {
                    throw new Refusal('removed');
                }
                $fresh = $row->status !== 'approved';
                $row->status = 'approved';
                $row->decline_reason = null;
                if ($fresh) {
                    $row->approved_by = $actor->id;
                    $row->approved_at = Carbon::now();
                }
                if ($action === 'feature') {
                    $row->is_featured = true;
                    $row->featured_order = (int) Testimonial::query()->where('is_featured', true)->max('featured_order') + 1;
                    $row->featured_until = $this->until($in['until'] ?? null);
                }
                $row->save();
                $this->notifications->delete(new Notification\DeclinedBlueprint($row));
                if ($action === 'feature') {
                    $this->notify(new Notification\FeaturedBlueprint($row), $row, $actor);
                } elseif ($fresh) {
                    $this->notify(new Notification\ApprovedBlueprint($row), $row, $actor);
                }
                break;

            case 'unfeature':
                $row->is_featured = false;
                $row->featured_until = null;
                $row->save();
                break;

            case 'decline':
            case 'spam':
                $row->status = $action === 'spam' ? 'spam' : 'declined';
                $row->decline_reason = $action === 'decline' ? (mb_substr(trim(strip_tags((string) ($in['reason'] ?? ''))), 0, 500) ?: null) : null;
                $row->is_featured = false;
                $row->save();
                if ($action === 'decline') {
                    $this->notify(new Notification\DeclinedBlueprint($row), $row, $actor);
                }
                break;

            case 'remove':
                $row->status = 'removed';
                $row->removed_at = Carbon::now();
                $row->save();
                break;

            case 'restore':
                $row->status = $row->approved_at ? 'approved' : 'pending';
                $row->removed_at = null;
                $row->save();
                break;

            case 'up':
            case 'down':
                $this->move($row, $action === 'up' ? -1 : 1);
                break;
        }

        if (! $wasLive && $row->isLive()) {
            $this->events->dispatch(new Approved($row, $actor));
        } elseif ($wasLive && ! $row->isLive()) {
            $this->events->dispatch(new Withdrawn($row));
        }
        $this->changed();
    }

    /** Written by someone whose testimonials need no review: live at once, no "approved" alert. */
    public function approved(Testimonial $row, User $actor, bool $notify): void
    {
        if ($notify) {
            $this->notify(new Notification\ApprovedBlueprint($row), $row, $actor);
        }
        $this->events->dispatch(new Approved($row, $actor));
        $this->changed();
    }

    public function editedByStaff(Testimonial $row, User $actor): void
    {
        $this->notify(new Notification\EditedBlueprint($row), $row, $actor);
        $this->changed();
    }

    /** Anything guests might see differently: forget the cached summaries. */
    public function changed(): void
    {
        $this->cache->forever(Summary::VERSION_KEY, (int) $this->cache->get(Summary::VERSION_KEY, 0) + 1);
    }

    private function notify(\Flarum\Notification\Blueprint\BlueprintInterface $blueprint, Testimonial $row, User $actor): void
    {
        // Never tell someone about their own action.
        if ($row->user && (int) $row->user_id !== (int) $actor->id) {
            $this->notifications->sync($blueprint, [$row->user]);
        }
    }

    /** Swap with the featured neighbour, after numbering the featured ones 1…n. */
    private function move(Testimonial $row, int $step): void
    {
        $featured = Testimonial::query()->where('is_featured', true)->orderBy('featured_order')->orderBy('id')->get();
        $ids = $featured->pluck('id')->all();
        $at = array_search($row->id, $ids, true);
        if ($at === false || ! isset($ids[$at + $step])) {
            return;
        }
        [$ids[$at], $ids[$at + $step]] = [$ids[$at + $step], $ids[$at]];
        foreach ($featured->keyBy('id') as $id => $model) {
            $position = array_search($id, $ids, true) + 1;
            if ($model->featured_order !== $position) {
                $model->featured_order = $position;
                $model->save();
            }
        }
        $row->featured_order = array_search($row->id, $ids, true) + 1;
    }

    /** "Feature until": a date in the future, or none. Anything else is refused, not a 500. */
    private function until(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new Refusal('until');
        }
        if ($date->isPast()) {
            throw new Refusal('until');
        }

        return $date;
    }
}
