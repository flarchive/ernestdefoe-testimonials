<?php

/*
 * Testimonials: members say what they think of the community, a tag or a download, with star
 * ratings and rated aspects. Moderated, with consent, and shown on /testimonials and in four
 * widgets (carousel, wall, spotlight, rating summary) in Bespoke, Page Builder and FoF widgets.
 *
 * Everything the forum reads goes through one JSON:API type (Api\TestimonialResource) and one
 * visibility rule (Scope\Visibility); bylines are enforced in Presenter, not in the browser.
 * Depot reviews come in as testimonials (Depot\Mirror) when Depot is installed.
 *
 * 🚨 String literals only below, never a class constant read at load time (Settings::KEY): it
 * autoloads during boot and fatals every request while a deploy is swapping composer's autoload
 * map. Constants inside closures are fine.
 */

use ErnestDefoe\Testimonials\Access;
use ErnestDefoe\Testimonials\Api;
use ErnestDefoe\Testimonials\Console;
use ErnestDefoe\Testimonials\Listener\Cleanup;
use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Notification;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

$extenders = [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The form, the queue and the profile list are chunks in here; undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/testimonials', 'testimonials', \ErnestDefoe\Testimonials\Http\PageContent::class)
        ->route('/testimonials/queue', 'testimonials.queue'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-testimonials', __DIR__.'/views'),

    new Extend\ApiResource(Api\TestimonialResource::class),

    (new Extend\Routes('api'))
        ->post('/testimonials/{id:\d+}/moderate', 'testimonials.moderate', Api\ModerateController::class)
        ->get('/testimonials-summary', 'testimonials.summary', Api\SummaryController::class),

    (new Extend\ErrorHandling())
        ->handler(\ErnestDefoe\Testimonials\Refusal::class, Api\RefusalHandler::class),

    (new Extend\ModelVisibility(Testimonial::class))
        ->scope(\ErnestDefoe\Testimonials\Scope\Visibility::class),

    (new Extend\Notification())
        ->type(Notification\ApprovedBlueprint::class, ['alert'])
        ->type(Notification\FeaturedBlueprint::class, ['alert'])
        ->type(Notification\DeclinedBlueprint::class, ['alert'])
        ->type(Notification\EditedBlueprint::class, ['alert']),

    // What the viewer may do (their groups and user row are already loaded: no queries), the
    // queue count for moderators (one COUNT, only for them), and the nudge (queries only when
    // the admin turned it on and the member passed its thresholds).
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('testimonialsCanCreate')
                ->get(fn ($forum, Context $context) => resolve(Access::class)->canCreate($context->getActor())),
            Schema\Str::make('testimonialsCreateRefusal')->nullable()
                ->visible(fn ($forum, Context $context) => ! $context->getActor()->isGuest())
                ->get(fn ($forum, Context $context) => resolve(Access::class)->createRefusal($context->getActor())),
            Schema\Integer::make('testimonialsPending')
                ->visible(fn ($forum, Context $context) => $context->getActor()->hasPermission('testimonials.moderate'))
                ->get(fn () => Testimonial::query()->where('status', 'pending')->count()),
            Schema\Boolean::make('testimonialsNudge')
                ->visible(fn ($forum, Context $context) => ! $context->getActor()->isGuest()
                    && (bool) resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-testimonials.nudge'))
                ->get(fn ($forum, Context $context) => resolve(\ErnestDefoe\Testimonials\Nudge::class)->shows($context->getActor())),
            Schema\Arr::make('testimonialsAspects')
                ->get(fn () => resolve(\ErnestDefoe\Testimonials\Settings::class)->aspects()),
            Schema\Boolean::make('testimonialsDepot')
                ->get(fn () => resolve(\ErnestDefoe\Testimonials\Extensions::class)->depot()),
            Schema\Boolean::make('testimonialsCanModerate')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('testimonials.moderate')),
        ]),

    (new Extend\Settings())
        ->default('ernestdefoe-testimonials.ratings', true)
        ->default('ernestdefoe-testimonials.anonymous', true)
        ->default('ernestdefoe-testimonials.depot_source', true)
        ->default('ernestdefoe-testimonials.min_length', 40)
        ->default('ernestdefoe-testimonials.max_length', 600)
        ->default('ernestdefoe-testimonials.min_posts', 0)
        ->default('ernestdefoe-testimonials.min_days', 0)
        ->default('ernestdefoe-testimonials.min_trust_level', 0)
        ->default('ernestdefoe-testimonials.nudge', false)
        ->default('ernestdefoe-testimonials.nudge_posts', 25)
        ->default('ernestdefoe-testimonials.nudge_days', 30)
        ->default('ernestdefoe-testimonials.widget_min_rating', 4)
        ->default('ernestdefoe-testimonials.stock_sidebar', false)
        ->default('ernestdefoe-testimonials.stock_top', false)
        ->default('ernestdefoe-testimonials.aspects', '[{"id":1,"name":"Customer service"},{"id":2,"name":"Quality"},{"id":3,"name":"Value"},{"id":4,"name":"Speed of support"}]')
        ->serializeToForum('testimonialsRatings', 'ernestdefoe-testimonials.ratings', 'boolval')
        ->serializeToForum('testimonialsAnonymous', 'ernestdefoe-testimonials.anonymous', 'boolval')
        ->serializeToForum('testimonialsMinLength', 'ernestdefoe-testimonials.min_length', 'intval')
        ->serializeToForum('testimonialsMaxLength', 'ernestdefoe-testimonials.max_length', 'intval')
        ->serializeToForum('testimonialsWidgetMin', 'ernestdefoe-testimonials.widget_min_rating', 'intval')
        ->serializeToForum('testimonialsStockSidebar', 'ernestdefoe-testimonials.stock_sidebar', 'boolval')
        ->serializeToForum('testimonialsStockTop', 'ernestdefoe-testimonials.stock_top', 'boolval'),

    (new Extend\Event())
        ->listen(\Flarum\User\Event\Deleting::class, [Cleanup::class, 'user'])
        ->listen(\Flarum\Post\Event\Deleted::class, fn () => resolve(Cleanup::class)->posts())
        ->listen(\Flarum\Discussion\Event\Deleted::class, fn () => resolve(Cleanup::class)->posts())
        ->listen('eloquent.deleted: Flarum\\Tags\\Tag', fn ($event, $models = null) => resolve(Cleanup::class)->subject('tag', (int) ($models[0] ?? $event)->id))
        // A setting can change what guests see (the Depot switch, aspects, ratings): forget the summaries.
        ->listen(\Flarum\Settings\Event\Saved::class, fn () => resolve(Moderation::class)->changed()),

    (new Extend\Console())
        ->command(Console\PurgeCommand::class)
        ->schedule(Console\PurgeCommand::class, function (ScheduledEvent $event) {
            $event->daily()->withoutOverlapping();
        })
        ->command(Console\SyncDepotCommand::class)
        ->schedule(Console\SyncDepotCommand::class, function (ScheduledEvent $event) {
            $event->daily()->withoutOverlapping();
        }),

    // A member's testimonials in their data export; erased with them.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\Testimonials\Gdpr\TestimonialsData::class),
        ]),

    // Depot reviews as testimonials, kept in step as they're written and deleted.
    (new Extend\Conditional())
        ->whenExtensionEnabled('ernestdefoe-depot', fn () => [
            (new Extend\Event())
                ->listen('eloquent.saved: ErnestDefoe\\Depot\\Model\\Review', fn ($event, $models = null) => resolve(\ErnestDefoe\Testimonials\Depot\Mirror::class)->sync(is_object($event) ? $event : $models[0]))
                ->listen('eloquent.deleted: ErnestDefoe\\Depot\\Model\\Review', fn ($event, $models = null) => resolve(\ErnestDefoe\Testimonials\Depot\Mirror::class)->remove(is_object($event) ? $event : $models[0]))
                ->listen('eloquent.deleted: ErnestDefoe\\Depot\\Model\\File', fn ($event, $models = null) => resolve(Cleanup::class)->subject('depot', (int) (is_object($event) ? $event : $models[0])->id)),
        ]),
];

// The Page Builder blocks only offer the widgets in its editor; the client fetches like every other host.
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    foreach (['Carousel', 'Wall', 'Spotlight', 'Rating'] as $block) {
        $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock('ErnestDefoe\\Testimonials\\Widget\\'.$block.'Block');
    }
}

return $extenders;
