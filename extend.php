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

use ErnestDefoe\Testimonials\Api;
use ErnestDefoe\Testimonials\Console;
use ErnestDefoe\Testimonials\Depot\Mirror;
use ErnestDefoe\Testimonials\Listener\Cleanup;
use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Notification;
use ErnestDefoe\Testimonials\Nudge;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
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

    // A page of testimonial notifications: authors, ratings and subjects loaded together.
    (new Extend\ApiResource(\Flarum\Api\Resource\NotificationResource::class))
        ->endpoint('index', fn (\Flarum\Api\Endpoint\Index $index) => $index->beforeSerialization(
            fn (\Flarum\Api\Context $context, array $results) => resolve(Api\NotificationSubjects::class)($context, $results)
        )),

    (new Extend\ErrorHandling())
        ->handler(\ErnestDefoe\Testimonials\Refusal::class, Api\RefusalHandler::class),

    (new Extend\ModelVisibility(Testimonial::class))
        ->scope(\ErnestDefoe\Testimonials\Scope\Visibility::class),

    (new Extend\Notification())
        ->type(Notification\ApprovedBlueprint::class, ['alert'])
        ->type(Notification\FeaturedBlueprint::class, ['alert'])
        ->type(Notification\DeclinedBlueprint::class, ['alert'])
        ->type(Notification\EditedBlueprint::class, ['alert']),

    // What the viewer may do, the queue count for moderators and the nudge (Api\ForumFields).
    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

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
        ->listen(\Flarum\Post\Event\Deleted::class, [Cleanup::class, 'postsGone'])
        ->listen(\Flarum\Discussion\Event\Deleted::class, [Cleanup::class, 'postsGone'])
        // Nudge caches whether a member has written one; a new or deleted row makes it ask again.
        ->listen('eloquent.created: ErnestDefoe\\Testimonials\\Testimonial', [Nudge::class, 'forget'])
        ->listen('eloquent.deleted: ErnestDefoe\\Testimonials\\Testimonial', [Nudge::class, 'forget'])
        // A setting can change what guests see (the Depot switch, aspects, ratings): forget the summaries.
        ->listen(\Flarum\Settings\Event\Saved::class, [Moderation::class, 'changed']),

    // A deleted tag takes the testimonials about it.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [
            new \ErnestDefoe\Testimonials\Extend\Integration('tags'),
            (new Extend\Event())->listen('eloquent.deleted: Flarum\\Tags\\Tag', [Cleanup::class, 'tag']),
        ]),

    (new Extend\Console())
        ->command(Console\PurgeCommand::class)
        ->schedule(Console\PurgeCommand::class, function (ScheduledEvent $event) {
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
            new \ErnestDefoe\Testimonials\Extend\Integration('depot'),
            (new Extend\Event())
                ->listen('eloquent.saved: ErnestDefoe\\Depot\\Model\\Review', [Mirror::class, 'saved'])
                ->listen('eloquent.deleted: ErnestDefoe\\Depot\\Model\\Review', [Mirror::class, 'deleted'])
                ->listen('eloquent.deleted: ErnestDefoe\\Depot\\Model\\File', [Cleanup::class, 'depotFile']),
            // Daily, to catch anything the listeners missed (and on demand).
            (new Extend\Console())
                ->command(Console\SyncDepotCommand::class)
                ->schedule(Console\SyncDepotCommand::class, function (ScheduledEvent $event) {
                    $event->daily()->withoutOverlapping();
                }),
        ]),

    // A minimum trust level to write one, and the level beside each author for moderators.
    (new Extend\Conditional())
        ->whenExtensionEnabled('ernestdefoe-trust-levels', fn () => [
            new \ErnestDefoe\Testimonials\Extend\Integration('trustLevels'),
        ]),

    // The Page Builder blocks only offer the widgets in its editor; the client fetches like every other host.
    (new Extend\Conditional())
        ->whenExtensionEnabled('ernestdefoe-page-builder', fn () => \ErnestDefoe\Testimonials\Widget\Blocks::extenders()),
];

return $extenders;
