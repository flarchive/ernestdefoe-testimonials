<?php

namespace ErnestDefoe\Testimonials\Widget;

class SpotlightBlock extends Block
{
    protected function widget(): string
    {
        return 'spotlight';
    }

    public function icon(): string
    {
        return 'fas fa-star';
    }

    protected function own(): array
    {
        return [
            ['key' => 'pick', 'type' => 'select', 'label' => $this->t('pick'), 'default' => 'daily', 'options' => [
                ['value' => 'daily', 'label' => $this->t('pick_daily')],
                ['value' => 'newest', 'label' => $this->t('pick_newest')],
            ]],
        ];
    }
}
