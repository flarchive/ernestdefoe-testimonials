<?php

namespace ErnestDefoe\Testimonials;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property int|null $source_id
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string|null $headline
 * @property string $body
 * @property int|null $rating
 * @property string $byline
 * @property Carbon|null $consent_at
 * @property bool $consent_external
 * @property string $status
 * @property string|null $decline_reason
 * @property bool $is_featured
 * @property int $featured_order
 * @property Carbon|null $featured_until
 * @property bool $hidden_by_author
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $edited_by_staff_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class Testimonial extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const SUBJECTS = ['community', 'tag', 'depot', 'post'];
    public const BYLINES = ['full', 'first', 'anonymous'];
    public const STATUSES = ['pending', 'approved', 'declined', 'spam', 'removed'];

    protected $table = 'testimonials';

    /** What may be set in one go: Depot's mirror (Depot\Mirror) and the summary's subject stubs. Writer sets each field itself. */
    protected $fillable = [
        'user_id', 'source', 'source_id', 'subject_type', 'subject_id', 'body', 'rating', 'byline',
        'status', 'consent_at', 'consent_external', 'approved_at',
    ];

    // AbstractModel turns timestamps off; this table has them.
    public $timestamps = true;

    protected $casts = [
        'rating' => 'integer',
        'consent_at' => 'datetime',
        'consent_external' => 'boolean',
        'is_featured' => 'boolean',
        'featured_order' => 'integer',
        'featured_until' => 'datetime',
        'hidden_by_author' => 'boolean',
        'approved_at' => 'datetime',
        'edited_by_staff_at' => 'datetime',
        'removed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isLive(): bool
    {
        return $this->status === 'approved' && ! $this->hidden_by_author;
    }

    /** Featured, and not past its "feature until" date. */
    public function isFeaturedNow(): bool
    {
        return $this->is_featured && (! $this->featured_until || $this->featured_until->isFuture());
    }

    /**
     * Only what everyone may see: approved, not hidden by its author, and from a source that is on.
     * Qualified columns: other scopes join.
     *
     * @param Builder<Testimonial> $query
     * @param bool $depot whether Depot reviews count (Settings::depotSource())
     */
    public function scopeLive(Builder $query, bool $depot): void
    {
        self::onlyLive($query, $depot);
    }

    /**
     * The same as the `live` scope, for a builder that isn't typed as this model's (a nested where).
     *
     * @param Builder<Testimonial> $query
     * @param bool $depot whether Depot reviews count (Settings::depotSource())
     */
    public static function onlyLive(Builder $query, bool $depot): void
    {
        $query->where('testimonials.status', 'approved')->where('testimonials.hidden_by_author', false);
        if (! $depot) {
            $query->where('testimonials.source', 'native');
        }
    }

    /**
     * Featured now, in the moderators' order.
     *
     * @param Builder<Testimonial> $query
     */
    public function scopeFeaturedNow(Builder $query): void
    {
        $query->where('testimonials.is_featured', true)
            ->where(fn (Builder $q) => $q->whereNull('testimonials.featured_until')->orWhere('testimonials.featured_until', '>', Carbon::now()));
    }
}
