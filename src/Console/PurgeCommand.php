<?php

namespace ErnestDefoe\Testimonials\Console;

use Carbon\Carbon;
use ErnestDefoe\Testimonials\Rating;
use ErnestDefoe\Testimonials\Settings;
use ErnestDefoe\Testimonials\Testimonial;
use Illuminate\Console\Command;

/** Daily: taken-down testimonials after 30 days (the undo window), declined and spam after 90. */
class PurgeCommand extends Command
{
    protected $signature = 'testimonials:purge';

    protected $description = 'Delete testimonials taken down 30 days ago, and declined or spam ones after 90 days.';

    public function handle(): int
    {
        $ids = Testimonial::query()
            ->where(fn ($q) => $q->where('status', 'removed')->where('removed_at', '<', Carbon::now()->subDays(Settings::REMOVED_DAYS)))
            ->orWhere(fn ($q) => $q->whereIn('status', ['declined', 'spam'])->where('updated_at', '<', Carbon::now()->subDays(Settings::DECLINED_DAYS)))
            ->pluck('id')->all();

        Rating::deleteWithTestimonials($ids);
        $this->info('Deleted '.count($ids).' testimonials.');

        return 0;
    }
}
