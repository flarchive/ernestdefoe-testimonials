<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Rated aspects. Forums that installed 1.0.x got this table from the first migration; there it
 * already exists and this does nothing.
 * 🚨 No hand-named indexes or keys: Laravel doesn't prefix explicit names, so
 * two forums sharing one database would collide. Generated names carry the prefix.
 */
return Migration::createTableIfNotExists('testimonial_ratings', function (Blueprint $table) {
    $table->unsignedInteger('testimonial_id');
    // An id from the `ernestdefoe-testimonials.aspects` setting.
    $table->unsignedInteger('aspect_id');
    $table->unsignedTinyInteger('rating');

    $table->primary(['testimonial_id', 'aspect_id']);
    $table->index('aspect_id');
    $table->foreign('testimonial_id')->references('id')->on('testimonials')->cascadeOnDelete();
});
