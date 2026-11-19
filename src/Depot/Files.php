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
     * Approved downloads this person may open whose title starts with $term, for the form's picker.
     *
     * @return list<array{id: int, title: string}>
     */
    public static function search(User $actor, string $term, int $limit = 8): array
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return self::visible($actor)->whereNotNull('depot_files.approved_at')
            ->where('depot_files.title', 'like', $like)
            ->orderBy('depot_files.title')->take($limit)->get(['depot_files.id', 'depot_files.title'])
            ->map(fn ($file) => ['id' => (int) $file->id, 'title' => (string) $file->title])->values()->all();
    }

    /**
     * Titles and slugs of these downloads, by id (labels for testimonials about them; the rows
     * themselves are only visible where the download is, Scope\Visibility).
     *
     * @param int[] $ids
     * @return array<int, array{title: string, slug: string}>
     */
    public static function labels(array $ids): array
    {
        $out = [];
        foreach (\ErnestDefoe\Depot\Model\File::query()->whereIn('id', $ids)->get(['id', 'title', 'slug']) as $file) {
            $out[(int) $file->id] = ['title' => (string) $file->title, 'slug' => (string) $file->slug];
        }

        return $out;
    }

    public static function idForSlug(string $slug): ?int
    {
        $id = \ErnestDefoe\Depot\Model\File::query()->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
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
