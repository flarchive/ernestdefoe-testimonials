<?php

namespace ErnestDefoe\Testimonials\Api;

use ErnestDefoe\Testimonials\Access;
use ErnestDefoe\Testimonials\Event\Withdrawn;
use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Presenter;
use ErnestDefoe\Testimonials\Settings;
use ErnestDefoe\Testimonials\Subjects;
use ErnestDefoe\Testimonials\Testimonial;
use ErnestDefoe\Testimonials\Writer;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `testimonials` JSON:API type: the page, the queue, a profile's list and every widget read
 * this one endpoint, so there is one set of rules.
 *
 * GET    /api/testimonials   live ones the viewer may see, newest first. Filters:
 *        subject=community|tag|depot|post, subjectId=N or slug=…, featured=1 (featured now, in order),
 *        notFeatured=1 (all but those), minRating=N, sort=featured (featured first, then newest), user=N (their named ones),
 *        mine=1 (the viewer's own, any stage), status=pending|declined|spam|removed|featured (moderators: the queue).
 * GET    /api/testimonials/{id}
 * POST   /api/testimonials   Writer::create        PATCH /api/testimonials/{id}   Writer::update
 * DELETE /api/testimonials/{id}  moderators
 *
 * The queue's actions are POST /api/testimonials/{id}/moderate (ModerateController).
 *
 * @extends AbstractDatabaseResource<Testimonial>
 */
class TestimonialResource extends AbstractDatabaseResource
{
    public const PAGE = 24;

    /** Attributes the form and the queue send; Writer applies them together. */
    private const INPUTS = ['headline', 'body', 'rating', 'byline', 'consent', 'consentExternal', 'subjectType', 'subjectId', 'aspects', 'hiddenByAuthor'];

    // Built on every API request: constructors only, no queries or settings reads.
    public function __construct(
        private readonly Presenter $presenter,
        private readonly Access $access,
        private readonly Writer $writer,
        private readonly Subjects $subjects,
        private readonly Moderation $moderation,
        private readonly Dispatcher $dispatcher,
        private readonly Settings $settings,
    ) {
    }

    public function type(): string
    {
        return 'testimonials';
    }

    public function model(): string
    {
        return Testimonial::class;
    }

    /** @param Builder<Testimonial> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereVisibleTo($context->getActor())->with($this->presenter->relations($context->getActor()));
    }

    public function endpoints(): array
    {
        return [
            tap(Endpoint\Index::make()->paginate(self::PAGE, 50), function (Endpoint\Index $index) {
                $index->paginationResolver = fn (BaseContext $context) => new PeekPagination($context, self::PAGE, 50);
            })
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters) => $this->listing($query, $pagination, $context, $filters)),
            Endpoint\Show::make(),
            Endpoint\Create::make()->authenticated(),
            Endpoint\Update::make()->authenticated(),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        $actor = fn (Context $context) => $context->getActor();
        $insider = fn (Testimonial $t, Context $context) => Presenter::canModerate($context->getActor()) || Presenter::isAuthor($t, $context->getActor());
        $mod = fn (Testimonial $t, Context $context) => Presenter::canModerate($context->getActor());
        $input = fn ($field) => $field->writable()->set(fn () => null);

        return [
            $input(Schema\Str::make('headline')->nullable()),
            $input(Schema\Str::make('body')),
            $input(Schema\Integer::make('rating')->nullable()),
            $input(Schema\Str::make('byline')),
            Schema\Str::make('source'),
            Schema\Boolean::make('isFeatured')->get(fn (Testimonial $t) => $t->isFeaturedNow()),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\DateTime::make('approvedAt')->property('approved_at')->nullable(),
            Schema\Arr::make('author')->get(fn (Testimonial $t, Context $context) => $this->presenter->author($t, $actor($context))),
            Schema\Arr::make('subject')->get(fn (Testimonial $t) => $this->presenter->subject($t)),
            $input(Schema\Arr::make('aspects')->get(fn (Testimonial $t) => $this->presenter->aspects($t))),

            // Write-only: the form's consent box, and what it's about (fixed once written).
            Schema\Boolean::make('consent')->visible(false)->writableOnCreate()->set(fn () => null),
            Schema\Str::make('subjectType')->visible(false)->writableOnCreate()->set(fn () => null),
            Schema\Integer::make('subjectId')->visible(false)->nullable()->writableOnCreate()->set(fn () => null),

            // Its author and moderators only.
            Schema\Str::make('status')->visible($insider),
            Schema\Str::make('declineReason')->property('decline_reason')->nullable()->visible($insider),
            $input(Schema\Boolean::make('consentExternal')->property('consent_external')->visible($insider)),
            $input(Schema\Boolean::make('hiddenByAuthor')->property('hidden_by_author')->visible($insider)),
            Schema\DateTime::make('featuredUntil')->property('featured_until')->nullable()->visible($mod),
            Schema\DateTime::make('editedByStaffAt')->property('edited_by_staff_at')->nullable()->visible($insider),
            Schema\DateTime::make('removedAt')->property('removed_at')->nullable()->visible($insider),

            Schema\Boolean::make('canEdit')->get(fn (Testimonial $t, Context $context) => $this->access->canEdit($t, $actor($context))),
            Schema\Boolean::make('canEditWording')->get(fn (Testimonial $t, Context $context) => $this->access->canEditWording($t, $actor($context))),
            Schema\Boolean::make('canRemove')->get(fn (Testimonial $t, Context $context) => $this->access->canRemove($t, $actor($context))),
            Schema\Boolean::make('canRestore')->get(fn (Testimonial $t, Context $context) => $this->access->canRestore($t, $actor($context))),
            Schema\Boolean::make('canHide')->get(fn (Testimonial $t, Context $context) => $this->access->canHide($t, $actor($context))),
            Schema\Boolean::make('canModerate')->get(fn (Testimonial $t, Context $context) => Presenter::canModerate($actor($context))),
        ];
    }

    /**
     * @param Builder<Testimonial> $query
     * @param array<string, mixed> $filters
     */
    private function listing(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $actor = $context->getActor();
        $query->select('testimonials.*');

        $this->whose($query, $actor, $filters);
        $this->about($query, $filters);

        $min = (int) ($filters['minRating'] ?? 0);
        if ($min >= 1 && $min <= 5) {
            $query->where('testimonials.rating', '>=', $min);
        }
        if (! empty($filters['notFeatured'])) {
            // The page lists these under its featured strip: no testimonial twice.
            $query->where(fn (Builder $q) => $q->where('testimonials.is_featured', false)
                ->orWhere('testimonials.featured_until', '<=', \Carbon\Carbon::now()));
        }
        $this->order($query, $filters);

        if ($pagination instanceof PeekPagination) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    /**
     * Which ones: the viewer's own, a moderator's queue tab, or the live ones (on a profile, only
     * those posted under the member's full name, unless it's their own or a moderator looks).
     *
     * @param Builder<Testimonial> $query
     * @param array<string, mixed> $filters
     */
    private function whose(Builder $query, User $actor, array $filters): void
    {
        $status = (string) ($filters['status'] ?? '');
        if (! empty($filters['mine'])) {
            $actor->assertRegistered();
            $query->where('testimonials.user_id', $actor->id);

            return;
        }
        if ($status !== '' && Presenter::canModerate($actor)) {
            $status === 'featured'
                ? $query->where('testimonials.status', 'approved')->where('testimonials.is_featured', true)
                : $query->where('testimonials.status', in_array($status, Testimonial::STATUSES, true) ? $status : 'pending');
        } else {
            $query->live($this->settings->depotSource());
        }

        if (isset($filters['user'])) {
            $query->where('testimonials.user_id', (int) $filters['user']);
            // A profile never lists what was posted anonymously or under a first name (it would name them).
            if (! Presenter::canModerate($actor) && (int) $filters['user'] !== (int) $actor->id) {
                $query->where('testimonials.byline', 'full');
            }
        }
    }

    /**
     * About what: the community, a tag, a download or a post; by id, or (from a widget's settings)
     * by the tag's or download's slug.
     *
     * @param Builder<Testimonial> $query
     * @param array<string, mixed> $filters
     */
    private function about(Builder $query, array $filters): void
    {
        $subject = (string) ($filters['subject'] ?? '');
        if (! in_array($subject, Testimonial::SUBJECTS, true)) {
            return;
        }
        $query->where('testimonials.subject_type', $subject);
        if ($subject !== 'community' && (isset($filters['subjectId']) || isset($filters['slug']))) {
            $id = isset($filters['subjectId']) ? (int) $filters['subjectId'] : $this->subjects->idForSlug($subject, (string) $filters['slug']);
            $query->where('testimonials.subject_id', $id ?? 0);
        }
    }

    /**
     * The featured strip in the moderators' order, or featured first then the newest, or the newest.
     *
     * @param Builder<Testimonial> $query
     * @param array<string, mixed> $filters
     */
    private function order(Builder $query, array $filters): void
    {
        if (! empty($filters['featured'])) {
            $query->featuredNow()->orderBy('testimonials.featured_order');
        } elseif (($filters['sort'] ?? '') === 'featured') {
            // 🚨 Raw SQL gets no table prefix: the columns go through the grammar.
            $grammar = $query->getQuery()->getGrammar();
            $featured = $grammar->wrap('testimonials.is_featured');
            $until = $grammar->wrap('testimonials.featured_until');
            $query->orderByRaw("CASE WHEN {$featured} = ? AND ({$until} IS NULL OR {$until} > ?) THEN 0 ELSE 1 END", [true, \Carbon\Carbon::now()])
                ->orderBy('testimonials.featured_order');
        }
        $query->orderByDesc('testimonials.created_at')->orderByDesc('testimonials.id');
    }

    /** @param Builder<Testimonial> $query */
    public function results(object $query, BaseContext $context): iterable
    {
        $rows = $query->get();
        $page = PeekPagination::for($context);
        if ($page) {
            $page->more = $rows->count() > $page->limit;
            $rows = $rows->take($page->limit);
        }
        // Subjects and aspect ratings for the whole page in one query each.
        $this->presenter->prime($rows);

        return $rows;
    }

    public function count(object $query, BaseContext $context): ?int
    {
        // Pages say whether there's a next one; counts come from the summary.
        return null;
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $this->writer->create($model, $this->attributes($context), $context->getActor());

        return $model;
    }

    /** @param Context $context */
    public function updating(object $model, BaseContext $context): ?object
    {
        $this->writer->update($model, $this->attributes($context), $context->getActor());

        return $model;
    }

    /** @param Context $context */
    public function delete(object $model, BaseContext $context): void
    {
        if (! Presenter::canModerate($context->getActor())) {
            throw new PermissionDeniedException();
        }
        $wasLive = $model->isLive();
        $model->delete();
        if ($wasLive) {
            $this->dispatcher->dispatch(new Withdrawn($model));
        }
        $this->moderation->changed();
    }

    /** @return array<string, mixed> */
    private function attributes(BaseContext $context): array
    {
        return array_intersect_key((array) ($context->body()['data']['attributes'] ?? []), array_flip(self::INPUTS));
    }
}
