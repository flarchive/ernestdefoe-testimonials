<?php

namespace ErnestDefoe\Testimonials;

use Flarum\Extension\ExtensionManager;

/** Which optional extensions are on, asked once per request (the manager reads its own cache). */
class Extensions
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function tags(): bool
    {
        return $this->on('flarum-tags', \Flarum\Tags\Tag::class);
    }

    public function depot(): bool
    {
        return $this->on('ernestdefoe-depot', 'ErnestDefoe\\Depot\\Model\\File');
    }

    public function trustLevels(): bool
    {
        return $this->on('ernestdefoe-trust-levels', null);
    }

    private function on(string $id, ?string $class): bool
    {
        return $this->memo[$id] ??= ($class === null || class_exists($class)) && $this->extensions->isEnabled($id);
    }
}
