<?php

namespace ErnestDefoe\Testimonials\Extend;

use ErnestDefoe\Testimonials\Extensions;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * Switches on one optional integration (tags, depot, trustLevels). extend.php adds it only inside
 * an Extend\Conditional for that extension, so "is it enabled?" is decided there, once.
 */
class Integration implements ExtenderInterface
{
    public function __construct(private readonly string $name)
    {
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->resolving(Extensions::class, fn (Extensions $extensions) => $extensions->enable($this->name));
    }
}
