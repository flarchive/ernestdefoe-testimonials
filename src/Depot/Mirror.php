<?php

namespace ErnestDefoe\Testimonials\Depot;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\Review;
use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Testimonial;

/**
 * Depot reviews shown as testimonials. A review rated 4★ or more, with words in it, is mirrored
 * as one live testimonial about its download (source `depot`), kept in step on every review save
 * and delete, and the whole set re-checked daily (testimonials:sync-depot). Edited below 4★ or
 * emptied, the mirror goes.
 *
 * 🚨 Reviewers never agreed to a testimonial. Depot has no consent field, so a mirror is shown on
 * the forum only: `consent_external` is always false (never featured outside the forum), the
 * byline is the name Depot already shows beside the review, the reviewer can take theirs out of
 * the widgets (hidden_by_author), and the admin can switch the source off (Testimonial::live).
 *
 * Only loaded when Depot is installed (extend.php checks first).
 */
class Mirror
{
    public function __construct(
        private readonly Moderation $moderation,
    ) {
    }

    /** The lowest rating a Depot review needs to show as a testimonial. */
    public const MIN_RATING = 4;

    public function sync(Review $review, bool $announce = true): void
    {
        $body = trim((string) $review->body);
        $row = Testimonial::query()->where('source', 'depot')->where('source_id', $review->id)->first();

        if ($body === '' || (int) $review->rating < self::MIN_RATING) {
            $row?->delete();
        } else {
            $row ??= new Testimonial(['source' => 'depot', 'source_id' => $review->id, 'status' => 'approved', 'byline' => 'full']);
            $at = $review->created_at ?? Carbon::now();
            $row->fill([
                'user_id' => $review->user_id,
                'subject_type' => 'depot',
                'subject_id' => $review->file_id,
                'body' => mb_substr($body, 0, 2000),
                'rating' => max(1, min(5, (int) $review->rating)),
                'consent_at' => $at,
                // Depot never asked: on the forum only, never outside it.
                'consent_external' => false,
                'approved_at' => $row->approved_at ?? $at,
            ]);
            if (! $row->exists) {
                $row->created_at = $at;
            }
            if (! $row->isDirty()) {
                return;
            }
            $row->save();
        }

        if ($announce) {
            $this->moderation->changed();
        }
    }

    /** `eloquent.saved` on a Depot review: the model, or the model in a payload array. */
    public function saved(mixed $event, mixed $models = null): void
    {
        $this->sync(is_object($event) ? $event : $models[0]);
    }

    /** `eloquent.deleted` on a Depot review. */
    public function deleted(mixed $event, mixed $models = null): void
    {
        $this->remove(is_object($event) ? $event : $models[0]);
    }

    public function remove(Review $review): void
    {
        if (Testimonial::query()->where('source', 'depot')->where('source_id', $review->id)->delete()) {
            $this->moderation->changed();
        }
    }

    /** Every review in step, and mirrors of reviews that are gone removed. @return int mirrors now */
    public function syncAll(): int
    {
        Review::query()->orderBy('id')->chunkById(200, function ($reviews) {
            foreach ($reviews as $review) {
                $this->sync($review, false);
            }
        });

        Testimonial::query()->where('source', 'depot')
            ->whereNotIn('source_id', Review::query()->withoutGlobalScopes()->select('id'))
            ->delete();
        $this->moderation->changed();

        return Testimonial::query()->where('source', 'depot')->count();
    }
}
