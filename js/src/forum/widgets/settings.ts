import app from 'flarum/forum/app';

/**
 * A widget's settings, the same keys in every host (Page Builder's blocks declare them in PHP:
 * src/Widget). Missing or odd values fall back to the defaults.
 */
export interface WidgetSettings {
  source: 'featured' | 'all' | 'community' | 'tag' | 'depot';
  slug: string;
  count: number;
  interval: number;
  showRating: boolean;
  columns: number;
  seeAll: boolean;
  pick: 'daily' | 'newest';
  showQuote: boolean;
  showButton: boolean;
  title: string;
}

const SOURCES = ['featured', 'all', 'community', 'tag', 'depot'];

export function normalize(raw: Record<string, any> = {}, kind: string): WidgetSettings {
  const num = (v: any, d: number, lo: number, hi: number) => Math.max(lo, Math.min(hi, parseInt(String(v), 10) || d));
  const bool = (v: any, d: boolean) => (v === undefined || v === null || v === '' ? d : v === true || v === 'true' || v === 1 || v === '1');
  return {
    source: SOURCES.includes(raw.source) ? raw.source : kind === 'rating' ? 'all' : 'featured',
    slug: String(raw.slug ?? '').trim(),
    count: num(raw.count, 6, 1, 12),
    interval: raw.interval === 0 || raw.interval === '0' ? 0 : num(raw.interval, 8, 3, 30),
    showRating: bool(raw.showRating, true),
    columns: num(raw.columns, 3, 1, 4),
    seeAll: bool(raw.seeAll, true),
    pick: raw.pick === 'newest' ? 'newest' : 'daily',
    showQuote: bool(raw.showQuote, true),
    showButton: bool(raw.showButton, true),
    title: String(raw.title ?? '').trim(),
  };
}

/** The list filters for a widget: the source, and the admin's "widgets show N★ and up". */
export function filtersFor(s: WidgetSettings, featuredOnly = false): Record<string, string | number> {
  const filters: Record<string, string | number> = {};
  if (s.source === 'featured' || featuredOnly) filters.sort = 'featured';
  if (featuredOnly) filters.featured = 1;
  if (s.source === 'community' || s.source === 'tag' || s.source === 'depot') {
    filters.subject = s.source;
    if (s.source !== 'community' && s.slug) filters.slug = s.slug;
  }
  // Only while ratings are on: unrated testimonials would otherwise never show anywhere.
  const min = app.forum.attribute<number>('testimonialsWidgetMin') ?? 4;
  if (min > 0 && app.forum.attribute('testimonialsRatings') !== false) filters.minRating = min;
  return filters;
}

/** The summary's parameters for a Rating summary widget. */
export function summaryParams(s: WidgetSettings): Record<string, string> {
  if (s.source === 'community' || s.source === 'tag' || s.source === 'depot') {
    return s.source !== 'community' && s.slug ? { subject: s.source, slug: s.slug } : { subject: s.source };
  }
  return {};
}

/** Bespoke's inspector fields. Labels are translation keys (Bespoke calls string methods on them). */
export function bespokeSchema(kind: string): any[] {
  const k = (key: string) => `ernestdefoe-testimonials.lib.widget.${key}`;
  const sources = (kind === 'rating' ? SOURCES.slice(1) : SOURCES).map((value) => ({ value, label: k(`source_${value}`) }));
  const source = [
    { key: 'source', type: 'select', label: k('source'), default: kind === 'rating' ? 'all' : 'featured', options: sources },
    { key: 'slug', type: 'text', label: k('slug'), default: '' },
  ];
  switch (kind) {
    case 'carousel':
      return [
        ...source,
        { key: 'count', type: 'number', label: k('count'), default: 6 },
        { key: 'interval', type: 'number', label: k('interval'), default: 8 },
        { key: 'showRating', type: 'toggle', label: k('show_rating'), default: true },
      ];
    case 'wall':
      return [
        ...source,
        { key: 'count', type: 'number', label: k('count'), default: 6 },
        {
          key: 'columns',
          type: 'select',
          label: k('columns'),
          default: '3',
          options: ['2', '3', '4'].map((value) => ({ value, label: value })),
        },
        { key: 'seeAll', type: 'toggle', label: k('see_all'), default: true },
      ];
    case 'spotlight':
      return [
        ...source,
        {
          key: 'pick',
          type: 'select',
          label: k('pick'),
          default: 'daily',
          options: [
            { value: 'daily', label: k('pick_daily') },
            { value: 'newest', label: k('pick_newest') },
          ],
        },
      ];
    default:
      return [
        ...source,
        { key: 'showQuote', type: 'toggle', label: k('show_quote'), default: true },
        { key: 'showButton', type: 'toggle', label: k('show_button'), default: true },
      ];
  }
}
