<?php

namespace ErnestDefoe\Testimonials;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * One rated aspect of a testimonial (the aspect ids come from the `ernestdefoe-testimonials.aspects`
 * setting). The table's key is (testimonial_id, aspect_id); rows are written and removed in sets
 * through queries, never one model at a time.
 *
 * @property int $testimonial_id
 * @property int $aspect_id
 * @property int $rating
 */
class Rating extends AbstractModel
{
    protected $table = 'testimonial_ratings';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['testimonial_id', 'aspect_id', 'rating'];

    protected $casts = [
        'testimonial_id' => 'integer',
        'aspect_id' => 'integer',
        'rating' => 'integer',
    ];

    /**
     * The ratings of these testimonials.
     *
     * @param int[] $ids
     * @return Builder<Rating>
     */
    public static function of(array $ids): Builder
    {
        return self::query()->whereIn('testimonial_id', $ids);
    }

    /**
     * Deletes these testimonials and their ratings (the foreign key cascades on MySQL and
     * PostgreSQL; SQLite runs without foreign keys unless asked, so the ratings go first).
     *
     * @param int[] $ids
     */
    public static function deleteWithTestimonials(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            self::of($chunk)->delete();
            Testimonial::query()->whereIn('id', $chunk)->delete();
        }
    }
}
