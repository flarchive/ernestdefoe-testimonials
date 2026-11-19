<?php

namespace ErnestDefoe\Testimonials\Tests\integration\api;

use ErnestDefoe\Testimonials\Depot\Files;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Depot's downloads are filtered through Depot's own visibility rules, whichever way the installed
 * Depot offers them. Each test loads one stand-in File model (each test runs in its own process).
 */
class DepotVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-testimonials');
    }

    #[Test]
    public function an_older_depot_is_read_through_its_visible_to_scope(): void
    {
        require_once __DIR__.'/../../fixtures/DepotFileOld.php';
        $this->app();

        $this->assertSame(['old'], Files::visibleIds(User::findOrFail(1))->getBindings());
    }

    #[Test]
    public function a_newer_depot_is_read_through_where_visible_to(): void
    {
        require_once __DIR__.'/../../fixtures/DepotFileNew.php';
        $this->app();

        $this->assertSame(['new'], Files::visibleIds(User::findOrFail(1))->getBindings());
    }
}
