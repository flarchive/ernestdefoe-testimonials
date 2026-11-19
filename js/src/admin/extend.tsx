import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import Aspects from './Aspects';

const EXT = 'ernestdefoe-testimonials';
const t = (key: string) => app.translator.trans(`${EXT}.admin.settings.${key}`, {}, true);
const setting = (key: string) => `${EXT}.${key}`;

/** The settings page and the three permissions. */
export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('ratings'), type: 'boolean', label: t('ratings'), help: t('ratings_help') }), 120)
    .customSetting(function (this: any) {
      return <Aspects page={this} />;
    }, 115)
    .setting(() => ({ setting: setting('anonymous'), type: 'boolean', label: t('anonymous'), help: t('anonymous_help') }), 110)
    .setting(() => ({ setting: setting('depot_source'), type: 'boolean', label: t('depot_source'), help: t('depot_source_help') }), 105)
    .setting(() => ({ setting: setting('min_length'), type: 'number', min: 1, max: 2000, label: t('min_length') }), 100)
    .setting(() => ({ setting: setting('max_length'), type: 'number', min: 40, max: 2000, label: t('max_length') }), 99)
    .setting(() => ({ setting: setting('min_posts'), type: 'number', min: 0, label: t('min_posts'), help: t('min_posts_help') }), 90)
    .setting(() => ({ setting: setting('min_days'), type: 'number', min: 0, label: t('min_days') }), 89)
    .customSetting(function (this: any) {
      // Only with Trust Levels on.
      if (!('ernestdefoe-trust-levels' in ((globalThis as any).flarum?.extensions || {}))) return null;
      return this.buildSettingComponent({ setting: setting('min_trust_level'), type: 'number', min: 0, max: 4, label: t('min_trust_level') });
    }, 88)
    .setting(
      () => ({
        setting: setting('widget_min_rating'),
        type: 'number',
        min: 0,
        max: 5,
        label: t('widget_min_rating'),
        help: t('widget_min_rating_help'),
      }),
      80
    )
    .setting(() => ({ setting: setting('stock_top'), type: 'boolean', label: t('stock_top'), help: t('stock_help') }), 70)
    .setting(() => ({ setting: setting('stock_sidebar'), type: 'boolean', label: t('stock_sidebar') }), 69)
    .setting(() => ({ setting: setting('nudge'), type: 'boolean', label: t('nudge'), help: t('nudge_help') }), 60)
    .setting(() => ({ setting: setting('nudge_posts'), type: 'number', min: 0, label: t('nudge_posts') }), 59)
    .setting(() => ({ setting: setting('nudge_days'), type: 'number', min: 0, label: t('nudge_days') }), 58)
    .permission(() => ({ permission: 'testimonials.view', icon: 'fas fa-quote-left', label: t('perm_view'), allowGuest: true }), 'view', 50)
    .permission(() => ({ permission: 'testimonials.create', icon: 'fas fa-quote-left', label: t('perm_create') }), 'start', 50)
    .permission(() => ({ permission: 'testimonials.autoApprove', icon: 'fas fa-check-double', label: t('perm_auto') }), 'start', 49)
    .permission(() => ({ permission: 'testimonials.moderate', icon: 'fas fa-quote-left', label: t('perm_moderate') }), 'moderate', 50),
];
