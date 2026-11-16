<?php

namespace ErnestDefoe\Testimonials;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rating summary: how many live testimonials, the average and the 5→1 spread (one grouped
 * query), and each aspect's average (one more). Guests share a cached answer per subject for ten
 * minutes; any change bumps the version (Moderation::changed) and the next guest rebuilds it.
 * One cache entry per subject, overwritten in place, so changes never pile up files.
 */
class Summary
{
    public const VERSION_KEY = 'ernestdefoe-testimonials.version';
    private const TTL = 600;

    public function __construct(
        private readonly Cache $cache,
        private readonly Settings $settings,
        private readonly Subjects $subjects,
    ) {
    }

    /**
     * @param bool $bySubject also list what the testimonials are about, with a count each (the page's chips)
     * @return array<string, mixed>
     */
    public function for(User $actor, ?string $type, ?int $id, bool $bySubject = false): array
    {
        $type = in_array($type, Testimonial::SUBJECTS, true) ? $type : null;
        $id = $type && $type !== 'community' ? $id : null;

        if (! $actor->isGuest()) {
            return $this->build($actor, $type, $id, $bySubject);
        }

        $key = 'ernestdefoe-testimonials.summary.'.($type ?? 'all').'.'.($id ?? 0).($bySubject ? '.s' : '');
        $version = (int) $this->cache->get(self::VERSION_KEY, 0);
        $hit = $this->cache->get($key);
        if (is_array($hit) && ($hit['v'] ?? null) === $version) {
            return $hit['data'];
        }
        $data = $this->build($actor, $type, $id, $bySubject);
        $this->cache->put($key, ['v' => $version, 'data' => $data], self::TTL);

        return $data;
    }

    /** @return array<string, mixed> */
    private function build(User $actor, ?string $type, ?int $id, bool $bySubject): array
    {
        $depot = $this->settings->depotSource();
        $scope = function (Builder $q) use ($actor, $type, $id, $depot) {
            $q->whereVisibleTo($actor);
            Testimonial::onlyLive($q, $depot);
            if ($type) {
                $q->where('testimonials.subject_type', $type);
                if ($id !== null) {
                    $q->where('testimonials.subject_id', $id);
                }
            }
        };

        [$count, $spread, $sum] = $this->stars($scope);
        $rated = array_sum($spread);

        return [
            'subjects' => $bySubject ? $this->bySubject($scope) : null,
            'count' => $count,
            'rated' => $rated,
            'average' => $rated ? round($sum / $rated, 1) : null,
            'spread' => $spread,
            'aspects' => $rated && $this->settings->ratings() ? $this->aspects($scope) : [],
        ];
    }

    // 🚨 Raw SQL gets no table prefix: every column below goes through the grammar (Summary::col).

    /**
     * How many, the 5→1 spread of the rated ones, and their star total.
     *
     * @param callable(Builder<Testimonial>): void $scope
     * @return array{int, array<int, int>, int}
     */
    private function stars(callable $scope): array
    {
        $rows = Testimonial::query()->tap($scope)
            ->selectRaw($this->col('testimonials.rating').' as stars, COUNT(*) as n')
            ->groupBy('testimonials.rating')
            ->toBase()->get();

        $spread = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $count = 0;
        $sum = 0;
        foreach ($rows as $row) {
            $n = (int) $row->n;
            $count += $n;
            if ($row->stars !== null && isset($spread[(int) $row->stars])) {
                $spread[(int) $row->stars] += $n;
                $sum += $n * (int) $row->stars;
            }
        }

        return [$count, $spread, $sum];
    }

    /**
     * Each configured aspect's average, in the admin's order, for the aspects anyone rated.
     *
     * @param callable(Builder<Testimonial>): void $scope
     * @return list<array{id: int, name: string, average: float, count: int}>
     */
    private function aspects(callable $scope): array
    {
        $names = array_column($this->settings->aspects(), 'name', 'id');
        if (! $names) {
            return [];
        }
        $byAspect = Testimonial::query()->tap($scope)
            ->join('testimonial_ratings', 'testimonial_ratings.testimonial_id', '=', 'testimonials.id')
            ->whereIn('testimonial_ratings.aspect_id', array_keys($names))
            ->selectRaw($this->col('testimonial_ratings.aspect_id').' as aspect, AVG('.$this->col('testimonial_ratings.rating').') as average, COUNT(*) as n')
            ->groupBy('testimonial_ratings.aspect_id')
            ->toBase()->get()->keyBy('aspect');

        $aspects = [];
        foreach ($names as $aspectId => $name) {
            if ($row = $byAspect->get($aspectId)) {
                $aspects[] = ['id' => (int) $aspectId, 'name' => (string) $name, 'average' => round((float) $row->average, 1), 'count' => (int) $row->n];
            }
        }

        return $aspects;
    }

    /**
     * The chips: how many per subject, most first. Testimonials from posts count as community ones.
     *
     * @param callable(Builder<Testimonial>): void $scope
     * @return list<array{type: string, id: int|null, label: string|null, count: int}>
     */
    private function bySubject(callable $scope): array
    {
        $groups = Testimonial::query()->tap($scope)
            ->selectRaw($this->col('testimonials.subject_type').' as type, '.$this->col('testimonials.subject_id').' as sid, COUNT(*) as n')
            ->groupBy('testimonials.subject_type', 'testimonials.subject_id')
            ->toBase()->get();
        // Labels for every group in one query per kind (Subjects).
        $rows = $groups->map(fn ($g) => new Testimonial(['subject_type' => $g->type, 'subject_id' => $g->sid === null ? null : (int) $g->sid]))->all();
        $this->subjects->prime($rows);

        $merged = [];
        foreach ($groups as $i => $g) {
            $s = $this->subjects->of($rows[$i]);
            if ($s['type'] !== 'community' && $s['type'] !== 'post' && $s['label'] === null) {
                continue;
            }
            $community = $s['type'] === 'post' || $s['type'] === 'community';
            $k = $community ? 'community' : $s['type'].':'.$s['id'];
            $merged[$k] ??= ['type' => $community ? 'community' : $s['type'], 'id' => $community ? null : $s['id'], 'label' => $community ? null : $s['label'], 'count' => 0];
            $merged[$k]['count'] += (int) $g->n;
        }
        usort($merged, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $merged;
    }

    /** A column, quoted and with the table prefix, for raw SQL. */
    private function col(string $column): string
    {
        return Testimonial::query()->getQuery()->getGrammar()->wrap($column);
    }
}
