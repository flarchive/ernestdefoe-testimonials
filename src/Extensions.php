<?php

namespace ErnestDefoe\Testimonials;

/**
 * Which optional extensions Testimonials works with right now. Nothing here asks the extension
 * manager: extend.php switches each one on from its Extend\Conditional block (Extend\Integration).
 */
class Extensions
{
    /** @var array<string, true> */
    private array $on = [];

    public function enable(string $name): void
    {
        $this->on[$name] = true;
    }

    public function tags(): bool
    {
        return isset($this->on['tags']);
    }

    public function depot(): bool
    {
        return isset($this->on['depot']);
    }

    public function trustLevels(): bool
    {
        return isset($this->on['trustLevels']);
    }
}
