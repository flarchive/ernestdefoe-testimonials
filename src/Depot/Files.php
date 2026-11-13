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
        return \ErnestDefoe\Depot\Model\File::query()->visibleTo($actor)->select('depot_files.id');
    }

    /** An approved download this person may open: one a testimonial can be about. */
    public static function canRate(User $actor, int $id): bool
    {
        return \ErnestDefoe\Depot\Model\File::query()->visibleTo($actor)
            ->whereNotNull('depot_files.approved_at')->where('depot_files.id', $id)->exists();
    }
}
