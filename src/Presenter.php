<?php

namespace ErnestDefoe\Testimonials;

use Flarum\User\User;

/**
 * Everything the forum reads about a testimonial, decided in one place so every way of fetching
 * one says the same thing. The resource owns it and primes a whole page of rows at once.
 *
 * 🚨 The byline is enforced here, not in the browser: an anonymous testimonial carries no name,
 * picture, username, id or join year for anyone but its author and moderators; "first name only" carries the
 * first word of the display name and no picture or username.
 */
class Presenter
{
    /** @var array<int, list<array{id: int, rating: int}>> */
    private array $ratings = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Subjects $subjects,
    ) {
    }

    /** @param iterable<Testimonial> $rows */
    public function prime(iterable $rows): void
    {
        // Always read afresh: a resource (and its presenter) can outlive one request in a
        // long-running worker, and a rating may have changed since.
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->id;
            $this->ratings[$row->id] = [];
        }
        if ($ids) {
            foreach (Rating::of($ids)->orderBy('aspect_id')->get() as $r) {
                $this->ratings[(int) $r->testimonial_id][] = ['id' => (int) $r->aspect_id, 'rating' => (int) $r->rating];
            }
        }
        $this->subjects->prime($rows);
    }

    public static function canModerate(User $actor): bool
    {
        return $actor->hasPermission('testimonials.moderate');
    }

    public static function isAuthor(Testimonial $row, User $actor): bool
    {
        return $actor->exists && (int) $row->user_id === (int) $actor->id;
    }

    /** @return array<string, mixed> */
    public function author(Testimonial $row, User $actor): array
    {
        $user = $row->user;
        $since = $user?->joined_at?->format('Y');
        $insider = self::canModerate($actor) || self::isAuthor($row, $actor);
        $name = (string) ($user->display_name ?? '');

        $out = match ($insider || $row->byline === 'full' ? 'full' : $row->byline) {
            'full' => ['name' => $name, 'avatarUrl' => $user?->avatar_url, 'username' => $user?->username, 'anonymous' => false],
            'first' => ['name' => strtok($name, " \t") ?: $name, 'avatarUrl' => null, 'username' => null, 'anonymous' => false],
            default => ['name' => null, 'avatarUrl' => null, 'username' => null, 'anonymous' => true],
        };
        // Anonymous means anonymous: not even the year they joined (on a small forum it narrows it down).
        $out['since'] = $out['anonymous'] ? null : $since;

        if (self::canModerate($actor) && $user) {
            $out['id'] = (int) $user->id;
            $out['commentCount'] = (int) $user->comment_count;
            $out['joinedAt'] = $user->joined_at?->toIso8601String();
            $level = $user->getAttribute('trust_level');
            $out['trustLevel'] = $level === null ? null : (int) $level;
        }

        return $out;
    }

    /** @return list<array{id: int, rating: int}> */
    public function aspects(Testimonial $row): array
    {
        if (! isset($this->ratings[$row->id])) {
            $this->prime([$row]);
        }
        // Only aspects the admin still has on (a removed one stops showing; its ratings stay).
        $on = array_flip($this->settings->aspectIds());

        return array_values(array_filter($this->ratings[$row->id], fn ($r) => isset($on[$r['id']])));
    }

    /** @return array<string, mixed> */
    public function subject(Testimonial $row): array
    {
        return $this->subjects->of($row);
    }
}
