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
        $scope = function (Builder $q) use ($actor, $type, $id) {
            $q->whereVisibleTo($actor);
            Testimonial::onlyLive($q);
            if ($type) {
                $q->where('testimonials.subject_type', $type);
                if ($id !== null) {
                    $q->where('testimonials.subject_id', $id);
                }
            }
        };

        // 🚨 Raw SQL gets no table prefix: every column goes through the grammar (Summary::col).
        $col = fn (string $column) => $this->col($column);

        $rows = Testimonial::query()->tap($scope)
            ->selectRaw($col('testimonials.rating').' as stars, COUNT(*) as n')
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
        $rated = array_sum($spread);

        $aspects = [];
        $names = array_column($this->settings->aspects(), 'name', 'id');
        if ($rated && $this->settings->ratings() && $names) {
            $byAspect = Testimonial::query()->tap($scope)
                ->join('testimonial_ratings', 'testimonial_ratings.testimonial_id', '=', 'testimonials.id')
                ->whereIn('testimonial_ratings.aspect_id', array_keys($names))
                ->selectRaw($col('testimonial_ratings.aspect_id').' as aspect, AVG('.$col('testimonial_ratings.rating').') as average, COUNT(*) as n')
                ->groupBy('testimonial_ratings.aspect_id')
                ->toBase()->get()->keyBy('aspect');
            foreach ($names as $aspectId => $name) {
                if ($row = $byAspect->get($aspectId)) {
                    $aspects[] = ['id' => $aspectId, 'name' => $name, 'average' => round((float) $row->average, 1), 'count' => (int) $row->n];
                }
            }
        }

        $subjects = null;
        if ($bySubject) {
            $groups = Testimonial::query()->tap($scope)
                ->selectRaw($col('testimonials.subject_type').' as type, '.$col('testimonials.subject_id').' as sid, COUNT(*) as n')
                ->groupBy('testimonials.subject_type', 'testimonials.subject_id')
                ->toBase()->get();
            // Labels for every group in one query per kind (Subjects).
            $rows = $groups->map(fn ($g) => new Testimonial(['subject_type' => $g->type, 'subject_id' => $g->sid === null ? null : (int) $g->sid]))->all();
            $this->subjects->prime($rows);
            $subjects = [];
            foreach ($groups as $i => $g) {
                $s = $this->subjects->of($rows[$i]);
                if ($s['type'] === 'community' || $s['type'] === 'post' || $s['label'] !== null) {
                    $subjects[] = ['type' => $s['type'], 'id' => $s['id'], 'label' => $s['label'], 'count' => (int) $g->n];
                }
            }
            // Testimonials from posts count as community ones on the chips.
            $merged = [];
            foreach ($subjects as $s) {
                $k = $s['type'] === 'post' ? 'community' : $s['type'].':'.$s['id'];
                $merged[$k] ??= ['type' => $s['type'] === 'post' ? 'community' : $s['type'], 'id' => $s['type'] === 'post' ? null : $s['id'], 'label' => $s['type'] === 'post' ? null : $s['label'], 'count' => 0];
                $merged[$k]['count'] += $s['count'];
            }
            usort($merged, fn ($a, $b) => $b['count'] <=> $a['count']);
            $subjects = $merged;
        }

        return [
            'subjects' => $subjects,
            'count' => $count,
            'rated' => $rated,
            'average' => $rated ? round($sum / $rated, 1) : null,
            'spread' => $spread,
            'aspects' => $aspects,
        ];
    }

    /** A column, quoted and with the table prefix, for raw SQL. */
    private function col(string $column): string
    {
        return Testimonial::query()->getQuery()->getGrammar()->wrap($column);
    }
}
