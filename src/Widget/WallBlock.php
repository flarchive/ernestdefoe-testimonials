<?php

namespace ErnestDefoe\Testimonials\Widget;

class WallBlock extends Block
{
    protected function widget(): string
    {
        return 'wall';
    }

    public function icon(): string
    {
        return 'fas fa-table-cells-large';
    }

    protected function own(): array
    {
        return [
            ['key' => 'count', 'type' => 'number', 'label' => $this->t('count'), 'default' => 6],
            ['key' => 'columns', 'type' => 'select', 'label' => $this->t('columns'), 'default' => '3',
                'options' => [['value' => '2', 'label' => '2'], ['value' => '3', 'label' => '3'], ['value' => '4', 'label' => '4']]],
            ['key' => 'seeAll', 'type' => 'toggle', 'label' => $this->t('see_all'), 'default' => true],
        ];
    }
}
