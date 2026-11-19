<?php

namespace ErnestDefoe\Testimonials\Console;

use ErnestDefoe\Testimonials\Depot\Mirror;
use Illuminate\Console\Command;

/**
 * Bring Depot reviews in as testimonials (and drop mirrors of deleted reviews). Daily, and on demand.
 * Registered only while Depot is enabled (extend.php), so it never runs without it.
 */
class SyncDepotCommand extends Command
{
    protected $signature = 'testimonials:sync-depot';

    protected $description = 'Mirror Depot reviews as testimonials, and remove mirrors of reviews that are gone.';

    public function handle(Mirror $mirror): int
    {
        $count = $mirror->syncAll();
        $this->info("{$count} Depot reviews are testimonials.");

        return 0;
    }
}
