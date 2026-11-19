<?php

namespace ErnestDefoe\Testimonials\Depot;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Depot's downloads, through Depot's own visibility scope (approved files outside locked
 * categories, plus the viewer's own, everything for its moderators). Only called when Depot is
 * enabled (Extensions::depot).
 */
class Files
{
    /** The ids of downloads this person may open, as a subquery. */
    public static function visibleIds(User $actor): Builder
    {
        return self::visible($actor)->select('depot_files.id');
    }

    /** An approved download this person may open: one a testimonial can be about. */
    public static function canRate(User $actor, int $id): bool
    {
        return self::visible($actor)->whereNotNull('depot_files.approved_at')->where('depot_files.id', $id)->exists();
    }

    /**
     * Depot's own visibility rules. Newer Depot releases keep them in Flarum's ModelVisibility
     * (whereVisibleTo); 1.5.x and earlier have their own visibleTo scope.
     */
    private static function visible(User $actor): Builder
    {
        $query = \ErnestDefoe\Depot\Model\File::query();

        return method_exists(\ErnestDefoe\Depot\Model\File::class, 'scopeWhereVisibleTo')
            ? $query->whereVisibleTo($actor)
            : $query->visibleTo($actor);
    }
}
