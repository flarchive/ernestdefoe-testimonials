import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

const KEY = 'ernestdefoe-testimonials.aspects';
const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-testimonials.admin.aspects.${key}`, params);

interface Aspect {
  id: number;
  name: string;
}

/**
 * The rated aspects ("Customer service", "Quality"…): add, rename, reorder, remove. Ids never
 * change, so a rename keeps every rating given; a removed aspect stops showing and its ratings stay.
 * Saved with the page's other settings (it writes the JSON into the page's setting stream).
 */
export default class Aspects extends Component<{ page: any }> {
  stream(): any {
    return this.attrs.page.setting(KEY);
  }

  list(): Aspect[] {
    try {
      const raw = this.stream()();
      const parsed = JSON.parse(raw === undefined || raw === null || raw === '' ? '[]' : raw);
      return Array.isArray(parsed) ? parsed.filter((a) => a && a.id && typeof a.name === 'string') : [];
    } catch (e) {
      return [];
    }
  }

  write(list: Aspect[]): void {
    this.stream()(JSON.stringify(list));
  }

  view() {
    const list = this.list();
    const nextId = list.reduce((max, a) => Math.max(max, Number(a.id)), 0) + 1;
    return (
      <div className="Form-group TestimonialsAspects">
        <label>{t('label')}</label>
        <p className="helpText">{t('help')}</p>
        <ol className="TestimonialsAspects-list">{list.map((aspect, i) => this.row(list, aspect, i))}</ol>
        <Button
          className="Button"
          icon="fas fa-plus"
          onclick={() => {
            list.push({ id: nextId, name: '' });
            this.write(list);
          }}
        >
          {t('add')}
        </Button>
      </div>
    );
  }

  /** One aspect: its name, move it up, remove it. Every change writes the whole list back. */
  row(list: Aspect[], aspect: Aspect, i: number) {
    const label = (key: string, params: Record<string, unknown> = {}) =>
      app.translator.trans(`ernestdefoe-testimonials.admin.aspects.${key}`, params, true);
    return (
      <li key={aspect.id}>
        <input
          className="FormControl"
          value={aspect.name}
          maxlength={60}
          aria-label={label('name', { index: i + 1 })}
          oninput={(e: InputEvent) => {
            list[i] = { ...aspect, name: (e.target as HTMLInputElement).value };
            this.write(list);
          }}
        />
        <Button
          className="Button Button--icon"
          icon="fas fa-arrow-up"
          disabled={i === 0}
          aria-label={label('up')}
          onclick={() => {
            [list[i - 1], list[i]] = [list[i], list[i - 1]];
            this.write(list);
          }}
        />
        <Button
          className="Button Button--icon"
          icon="fas fa-trash-can"
          aria-label={label('remove')}
          onclick={() => {
            list.splice(i, 1);
            this.write(list);
          }}
        />
      </li>
    );
  }
}
