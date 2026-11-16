<?php

namespace ErnestDefoe\Testimonials;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Testimonials' settings, read with their defaults (extend.php declares the same defaults
 * with string literals, for the admin page).
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-testimonials.';

    public const DEFAULT_ASPECTS = '[{"id":1,"name":"Customer service"},{"id":2,"name":"Quality"},{"id":3,"name":"Value"},{"id":4,"name":"Speed of support"}]';

    /** A taken-down testimonial's words are kept this long for an undo, then deleted. */
    public const REMOVED_DAYS = 30;

    /** Declined and spam testimonials are deleted after this long. */
    public const DECLINED_DAYS = 90;

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null || $value === '' ? $default : (bool) $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function ratings(): bool
    {
        return $this->bool('ratings', true);
    }

    public function anonymous(): bool
    {
        return $this->bool('anonymous', true);
    }

    public function depotSource(): bool
    {
        return $this->bool('depot_source', true);
    }

    public function minLength(): int
    {
        return max(1, $this->int('min_length', 40));
    }

    public function maxLength(): int
    {
        return min(2000, max($this->minLength(), $this->int('max_length', 600)));
    }

    /**
     * The rated aspects, in order: ids never change, so renaming keeps every rating and
     * removing one only stops it showing. An empty list is fine (no aspects).
     *
     * @return list<array{id: int, name: string}>
     */
    public function aspects(): array
    {
        $raw = $this->settings->get(self::PREFIX.'aspects');
        $list = json_decode($raw === null ? self::DEFAULT_ASPECTS : (string) $raw, true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id > 0 && $name !== '' && ! isset($out[$id])) {
                $out[$id] = ['id' => $id, 'name' => mb_substr($name, 0, 60)];
            }
        }

        return array_values($out);
    }

    /** @return list<int> */
    public function aspectIds(): array
    {
        return array_column($this->aspects(), 'id');
    }
}
