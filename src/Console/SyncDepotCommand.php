<?php

namespace ErnestDefoe\Testimonials\Console;

use Flarum\Extension\ExtensionManager;
use Illuminate\Console\Command;

/** Bring Depot reviews in as testimonials (and drop mirrors of deleted reviews). Daily, and on demand. */
class SyncDepotCommand extends Command
{
    protected $signature = 'testimonials:sync-depot';

    protected $description = 'Mirror Depot reviews as testimonials, and remove mirrors of reviews that are gone.';

    public function handle(ExtensionManager $extensions): int
    {
        if (! class_exists(\ErnestDefoe\Depot\Model\Review::class) || ! $extensions->isEnabled('ernestdefoe-depot')) {
            $this->info('Depot is not enabled: nothing to do.');

            return 0;
        }
        $count = resolve(\ErnestDefoe\Testimonials\Depot\Mirror::class)->syncAll();
        $this->info("{$count} Depot reviews are testimonials.");

        return 0;
    }
}
