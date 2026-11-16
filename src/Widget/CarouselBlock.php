<?php

namespace ErnestDefoe\Testimonials\Widget;

class CarouselBlock extends Block
{
    protected function widget(): string
    {
        return 'carousel';
    }

    public function icon(): string
    {
        return 'fas fa-quote-left';
    }

    protected function own(): array
    {
        return [
            ['key' => 'count', 'type' => 'number', 'label' => $this->t('count'), 'default' => 6],
            ['key' => 'interval', 'type' => 'number', 'label' => $this->t('interval'), 'help' => $this->t('interval_help'), 'default' => 8],
            ['key' => 'showRating', 'type' => 'toggle', 'label' => $this->t('show_rating'), 'default' => true],
        ];
    }
}
