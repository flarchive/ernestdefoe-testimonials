<?php

namespace ErnestDefoe\Testimonials\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A Testimonials widget as a Page Builder block. resolve() hands Page Builder nothing: the client
 * fetches /api/testimonials like every other host, so there is one source and one set of rules.
 * Registered from extend.php only when Page Builder is installed. The setting keys are the same
 * in every host (js/src/forum/widgets/settings.ts).
 */
abstract class Block implements BlockInterface
{
    public function __construct(protected readonly TranslatorInterface $translator)
    {
    }

    abstract protected function widget(): string;

    /** @return list<array<string, mixed>> */
    abstract protected function own(): array;

    protected function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-testimonials.lib.widget.'.$key);
    }

    public function type(): string
    {
        return 'testimonials-'.$this->widget();
    }

    public function name(): string
    {
        return $this->t($this->widget().'_title');
    }

    public function category(): string
    {
        return 'content';
    }

    public function defaultSettings(): array
    {
        $out = [];
        foreach ($this->settingsSchema() as $field) {
            $out[$field['key']] = $field['default'];
        }

        return $out;
    }

    public function settingsSchema(): array
    {
        return [...$this->source(), ...$this->own()];
    }

    /** @return list<array<string, mixed>> */
    protected function source(bool $withFeatured = true): array
    {
        $options = [];
        foreach ($withFeatured ? ['featured', 'all', 'community', 'tag', 'depot'] : ['all', 'community', 'tag', 'depot'] as $value) {
            $options[] = ['value' => $value, 'label' => $this->t('source_'.$value)];
        }

        return [
            ['key' => 'source', 'type' => 'select', 'label' => $this->t('source'), 'default' => $withFeatured ? 'featured' : 'all', 'options' => $options],
            ['key' => 'slug', 'type' => 'text', 'label' => $this->t('slug'), 'help' => $this->t('slug_help'), 'default' => ''],
        ];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
