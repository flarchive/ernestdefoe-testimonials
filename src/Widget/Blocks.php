<?php

namespace ErnestDefoe\Testimonials\Widget;

use Ernestdefoe\PageBuilder\Extend\PageBuilderBlock;

/**
 * The four widgets as Page Builder blocks. Only called from extend.php's Conditional, i.e. when
 * Page Builder is enabled, so its extender class is there.
 */
class Blocks
{
    /** @return list<PageBuilderBlock> */
    public static function extenders(): array
    {
        return array_map(
            fn (string $block) => new PageBuilderBlock(__NAMESPACE__.'\\'.$block.'Block'),
            ['Carousel', 'Wall', 'Spotlight', 'Rating'],
        );
    }
}
