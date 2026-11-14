<?php

namespace ErnestDefoe\Testimonials\Widget;

class RatingBlock extends Block
{
    protected function widget(): string
    {
        return 'rating';
    }

    public function icon(): string
    {
        return 'fas fa-chart-simple';
    }

    public function settingsSchema(): array
    {
        return [...$this->source(false), ...$this->own()];
    }

    protected function own(): array
    {
        return [
            ['key' => 'showQuote', 'type' => 'toggle', 'label' => $this->t('show_quote'), 'default' => true],
            ['key' => 'showButton', 'type' => 'toggle', 'label' => $this->t('show_button'), 'default' => true],
        ];
    }
}
