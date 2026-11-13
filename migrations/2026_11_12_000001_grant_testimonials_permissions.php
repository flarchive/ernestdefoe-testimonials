<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'testimonials.view' => Group::GUEST_ID,
    'testimonials.create' => Group::MEMBER_ID,
    'testimonials.moderate' => Group::MODERATOR_ID,
]);
