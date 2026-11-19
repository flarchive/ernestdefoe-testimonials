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
    /** Where a row keeps its aspect ratings once read (a relation slot: never saved). */
    private const RATINGS = 'aspectRatings';

    public function __construct(
        private readonly Settings $settings,
        private readonly Subjects $subjects,
        private readonly TrustLevel $trust,
    ) {
    }

    /**
     * What a list of testimonials loads with each one: the author, and for moderators (who see
     * the author's trust level) Trust Levels' state row too, so the level costs no query per row.
     *
     * @return list<string>
     */
    public function relations(User $actor): array
    {
        $trust = self::canModerate($actor) ? $this->trust->relation() : null;

        return $trust ? ['user', "user.{$trust}"] : ['user'];
    }

    /**
     * Reads the ratings and subjects of these rows (those not read yet) in one query each, and keeps
     * them on each row: they live as long as the row (one request), so nothing is ever stale.
     *
     * @param iterable<Testimonial> $rows
     */
    public function prime(iterable $rows): void
    {
        $todo = [];
        foreach ($rows as $row) {
            if (! $row->relationLoaded(self::RATINGS)) {
                $todo[(int) $row->id] = [];
            }
        }
        if ($todo) {
            foreach (Rating::of(array_keys($todo))->orderBy('aspect_id')->get() as $r) {
                $todo[(int) $r->testimonial_id][] = ['id' => (int) $r->aspect_id, 'rating' => (int) $r->rating];
            }
            foreach ($rows as $row) {
                if (isset($todo[(int) $row->id])) {
                    $row->setRelation(self::RATINGS, $todo[(int) $row->id]);
                }
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
            $out['trustLevel'] = $this->trust->of($user);
        }

        return $out;
    }

    /** @return list<array{id: int, rating: int}> */
    public function aspects(Testimonial $row): array
    {
        if (! $row->relationLoaded(self::RATINGS)) {
            $this->prime([$row]);
        }
        // Only aspects the admin still has on (a removed one stops showing; its ratings stay).
        $on = array_flip($this->settings->aspectIds());

        return array_values(array_filter((array) $row->getRelation(self::RATINGS), fn ($r) => isset($on[$r['id']])));
    }

    /** @return array<string, mixed> */
    public function subject(Testimonial $row): array
    {
        return $this->subjects->of($row);
    }
}
