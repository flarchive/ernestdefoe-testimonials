import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

const EXT = 'ernestdefoe-testimonials';

export { default as extend } from './extend';

app.initializers.add(EXT, () => {
  registerEditorWidgets(app, EXT, [
    {
      key: 'testimonialsCarousel',
      icon: 'fas fa-quote-left',
      title: () => app.translator.trans(`${EXT}.lib.widget.carousel_title`),
      placement: 'top',
    },
    {
      key: 'testimonialsWall',
      icon: 'fas fa-table-cells-large',
      title: () => app.translator.trans(`${EXT}.lib.widget.wall_title`),
      placement: 'top',
    },
    { key: 'testimonialsSpotlight', icon: 'fas fa-star', title: () => app.translator.trans(`${EXT}.lib.widget.spotlight_title`), placement: 'end' },
    { key: 'testimonialsRating', icon: 'fas fa-chart-simple', title: () => app.translator.trans(`${EXT}.lib.widget.rating_title`), placement: 'end' },
  ]);
});
