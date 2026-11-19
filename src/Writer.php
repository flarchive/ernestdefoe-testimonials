<?php

namespace ErnestDefoe\Testimonials;

use Carbon\Carbon;
use ErnestDefoe\Testimonials\Event\Withdrawn;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Writing and editing a testimonial. The text is plain (tags stripped, no formatting), so nothing
 * hidden in a post can come along; the rules depend on each other, so they're applied together.
 */
class Writer
{
    /** A member can have this many waiting for review at once. */
    public const PENDING_LIMIT = 3;

    /** Seconds a submit waits for the member's previous one to finish saving. */
    public const LOCK_WAIT = 5;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly Access $access,
        private readonly Extensions $extensions,
        private readonly Moderation $moderation,
        private readonly Dispatcher $events,
        private readonly Cache $cache,
    ) {
    }

    /** @param array<string, mixed> $in */
    public function create(Testimonial $row, array $in, User $actor): void
    {
        if ($why = $this->access->createRefusal($actor)) {
            throw new Refusal($why, $why === 'permission' ? 403 : 422);
        }
        if (empty($in['consent'])) {
            throw new Refusal('no_consent');
        }

        // One write at a time per member: the "already written" and pending-limit checks and the
        // save happen together, so two quick submits can't both pass the checks.
        $approve = $this->exclusive((int) $actor->id, function () use ($row, $in, $actor): bool {
            [$type, $id] = $this->subject($in, $actor);
            $taken = Testimonial::query()->where('user_id', $actor->id)->where('source', 'native')
                ->where('subject_type', $type)->where('status', '!=', 'removed');
            $id === null ? $taken->whereNull('subject_id') : $taken->where('subject_id', $id);
            if ($taken->exists()) {
                throw new Refusal('already_written');
            }
            if (Testimonial::query()->where('user_id', $actor->id)->where('status', 'pending')->count() >= self::PENDING_LIMIT) {
                throw new Refusal('pending_limit');
            }

            $row->user_id = $actor->id;
            $row->source = 'native';
            $row->subject_type = $type;
            $row->subject_id = $id;
            $row->consent_at = Carbon::now();
            $this->fill($row, $in, true);

            $approve = $this->access->autoApproves($actor);
            $row->status = $approve ? 'approved' : 'pending';
            if ($approve) {
                $row->approved_by = $actor->id;
                $row->approved_at = Carbon::now();
            }

            $aspects = $this->aspects($in);
            $this->db->transaction(function () use ($row, $aspects) {
                $row->save();
                $this->saveAspects($row, $aspects);
            });

            return $approve;
        });

        if ($approve) {
            $this->moderation->approved($row, $actor);
        }
    }

    /**
     * The author's edit (goes back for review unless they need none), the author hiding it from
     * Depot, or a moderator's change to the wording only.
     *
     * @param array<string, mixed> $in
     */
    public function update(Testimonial $row, array $in, User $actor): void
    {
        if (array_key_exists('hiddenByAuthor', $in)) {
            $this->hide($row, (bool) $in['hiddenByAuthor'], $actor);
        } elseif ($this->access->canEdit($row, $actor)) {
            $this->edit($row, $in, $actor);
        } elseif ($this->access->canEditWording($row, $actor)) {
            $this->editWording($row, $in, $actor);
        } else {
            throw new PermissionDeniedException();
        }
    }

    private function hide(Testimonial $row, bool $hidden, User $actor): void
    {
        if (! $this->access->canHide($row, $actor)) {
            throw new PermissionDeniedException();
        }
        $row->hidden_by_author = $hidden;
        $row->save();
        $this->moderation->changed();
    }

    /** @param array<string, mixed> $in */
    private function edit(Testimonial $row, array $in, User $actor): void
    {
        $wasLive = $row->isLive();
        $this->fill($row, $in, false);
        // A changed testimonial is read again before it goes back up.
        $changed = $row->isDirty(['body', 'headline', 'rating', 'byline']) || $this->aspectsChanged($row, $in);
        if ($changed && $row->status !== 'pending' && ! $this->access->autoApproves($actor)) {
            $row->status = 'pending';
        }
        $aspects = array_key_exists('aspects', $in) ? $this->aspects($in) : null;
        $this->db->transaction(function () use ($row, $aspects) {
            $row->save();
            if ($aspects !== null) {
                $this->saveAspects($row, $aspects);
            }
        });
        if ($wasLive && ! $row->isLive()) {
            $this->events->dispatch(new Withdrawn($row));
        }
        $this->moderation->changed();
    }

    /** @param array<string, mixed> $in */
    private function editWording(Testimonial $row, array $in, User $actor): void
    {
        $before = [$row->body, $row->headline];
        if (array_key_exists('body', $in)) {
            $row->body = $this->body($in['body']);
        }
        if (array_key_exists('headline', $in)) {
            $row->headline = $this->headline($in['headline']);
        }
        if ([$row->body, $row->headline] !== $before) {
            $row->edited_by_staff_at = Carbon::now();
            $row->save();
            $this->moderation->editedByStaff($row, $actor);
        }
    }

    /** @param array<string, mixed> $in */
    private function fill(Testimonial $row, array $in, bool $creating): void
    {
        if ($creating || array_key_exists('body', $in)) {
            $row->body = $this->body($in['body'] ?? '');
        }
        if ($creating || array_key_exists('headline', $in)) {
            $row->headline = $this->headline($in['headline'] ?? null);
        }
        if ($creating || array_key_exists('rating', $in)) {
            $rating = $in['rating'] ?? null;
            $row->rating = $this->settings->ratings() && is_numeric($rating) && $rating >= 1 && $rating <= 5 ? (int) $rating : null;
        }
        if ($creating || array_key_exists('byline', $in)) {
            $byline = in_array($in['byline'] ?? 'full', Testimonial::BYLINES, true) ? ($in['byline'] ?? 'full') : 'full';
            if ($byline === 'anonymous' && ! $this->settings->anonymous()) {
                throw new Refusal('anonymous_off');
            }
            $row->byline = $byline;
        }
        if ($creating || array_key_exists('consentExternal', $in)) {
            $row->consent_external = (bool) ($in['consentExternal'] ?? false);
        }
    }

    private function body(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $text)) ?? '';
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');
        $length = mb_strlen($text);
        if ($length < $this->settings->minLength()) {
            throw new Refusal('too_short');
        }
        if ($length > $this->settings->maxLength()) {
            throw new Refusal('too_long');
        }

        return $text;
    }

    private function headline(mixed $value): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 120);
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: string, 1: int|null}
     */
    private function subject(array $in, User $actor): array
    {
        $type = (string) ($in['subjectType'] ?? 'community');
        $id = isset($in['subjectId']) && is_numeric($in['subjectId']) ? (int) $in['subjectId'] : 0;

        $ok = match ($type) {
            'community' => true,
            'tag' => $id && $this->extensions->tags() && \Flarum\Tags\Tag::whereVisibleTo($actor)->where('tags.id', $id)->exists(),
            'depot' => $id && $this->extensions->depot()
                && \ErnestDefoe\Testimonials\Depot\Files::canRate($actor, $id),
            'post' => $id && Post::whereVisibleTo($actor)->where('posts.id', $id)->where('posts.type', 'comment')->where('posts.user_id', $actor->id)->exists(),
            default => false,
        };
        if (! $ok) {
            throw new Refusal('subject');
        }

        return [$type, $type === 'community' ? null : $id];
    }

    /**
     * @param array<string, mixed> $in
     * @return array<int, int> aspect id => rating
     */
    private function aspects(array $in): array
    {
        if (! $this->settings->ratings()) {
            return [];
        }
        $on = array_flip($this->settings->aspectIds());
        $out = [];
        foreach ((array) ($in['aspects'] ?? []) as $item) {
            $id = (int) ($item['id'] ?? 0);
            $rating = $item['rating'] ?? null;
            if (isset($on[$id]) && is_numeric($rating) && $rating >= 1 && $rating <= 5) {
                $out[$id] = (int) $rating;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $in */
    private function aspectsChanged(Testimonial $row, array $in): bool
    {
        if (! array_key_exists('aspects', $in)) {
            return false;
        }
        $now = Rating::of([$row->id])->pluck('rating', 'aspect_id')->map(fn ($r) => (int) $r)->all();
        $next = $this->aspects($in);
        ksort($now);
        ksort($next);

        return $now !== $next;
    }

    /** @param array<int, int> $aspects */
    private function saveAspects(Testimonial $row, array $aspects): void
    {
        Rating::of([$row->id])->delete();
        if ($aspects) {
            Rating::query()->insert(array_map(
                fn ($id, $rating) => ['testimonial_id' => $row->id, 'aspect_id' => $id, 'rating' => $rating],
                array_keys($aspects),
                $aspects,
            ));
        }
    }

    /**
     * Runs $work holding this member's write lock (waits up to LOCK_WAIT seconds for an earlier submit).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function exclusive(int $user, callable $work): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $work();
        }

        try {
            return $store->lock("ernestdefoe-testimonials.write.{$user}", 10)->block(self::LOCK_WAIT, $work);
        } catch (LockTimeoutException) {
            // An earlier submit still holds it: say so (409), never a server error.
            throw new Refusal('busy', 409);
        }
    }
}
