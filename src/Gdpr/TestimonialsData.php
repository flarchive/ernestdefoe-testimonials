<?php

namespace ErnestDefoe\Testimonials\Gdpr;

use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Gdpr\Data\Type;

/** A member's testimonials in their data export; erased with them. */
class TestimonialsData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-testimonials.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-testimonials.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $rows = Testimonial::query()->where('user_id', $this->user->id)->orderBy('id')
            ->get(['subject_type', 'subject_id', 'headline', 'body', 'rating', 'byline', 'status', 'consent_at', 'consent_external', 'created_at'])
            ->toArray();

        return [['testimonials/testimonials.json' => $this->encodeForExport($rows)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $ids = Testimonial::query()->where('user_id', $this->user->id)->pluck('id')->all();
        if ($ids) {
            resolve(\Illuminate\Database\ConnectionInterface::class)->table('testimonial_ratings')->whereIn('testimonial_id', $ids)->delete();
            Testimonial::query()->whereIn('id', $ids)->delete();
        }
    }
}
