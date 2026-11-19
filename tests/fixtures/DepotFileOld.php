<?php

namespace ErnestDefoe\Depot\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** A stand-in for Depot's File model. Depot 1.5.x: its own visibleTo scope. Marks the query so the test sees which ran. */
class File extends AbstractModel
{
    protected $table = 'depot_files';

    /** @param Builder<File> $query */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        $query->where('depot_files.scope', 'old');
    }
}
