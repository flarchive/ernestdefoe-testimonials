<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * 🚨 No hand-named indexes or keys: Laravel doesn't prefix explicit names, so
 * two forums sharing one database would collide. Generated names carry the prefix.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('testimonials')) {
            $schema->create('testimonials', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');

                // native: written here. depot: a Depot review, mirrored (Depot\Mirror).
                $table->string('source', 10)->default('native');
                $table->unsignedInteger('source_id')->nullable();

                // community | tag | depot | post
                $table->string('subject_type', 12)->default('community');
                $table->unsignedInteger('subject_id')->nullable();

                $table->string('headline', 120)->nullable();
                $table->text('body');
                $table->unsignedTinyInteger('rating')->nullable();

                // full | first | anonymous
                $table->string('byline', 10)->default('full');
                $table->dateTime('consent_at')->nullable();
                $table->boolean('consent_external')->default(false);

                // pending | approved | declined | spam | removed
                $table->string('status', 10)->default('pending');
                $table->string('decline_reason', 500)->nullable();
                $table->boolean('is_featured')->default(false);
                $table->unsignedInteger('featured_order')->default(0);
                $table->dateTime('featured_until')->nullable();
                // The author took a mirrored Depot review out of the widgets.
                $table->boolean('hidden_by_author')->default(false);

                $table->unsignedInteger('approved_by')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->dateTime('edited_by_staff_at')->nullable();
                $table->dateTime('removed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'is_featured']);
                $table->index(['subject_type', 'subject_id', 'status']);
                $table->index(['source', 'source_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('testimonial_ratings')) {
            $schema->create('testimonial_ratings', function (Blueprint $table) {
                $table->unsignedInteger('testimonial_id');
                // An id from the `ernestdefoe-testimonials.aspects` setting.
                $table->unsignedInteger('aspect_id');
                $table->unsignedTinyInteger('rating');

                $table->primary(['testimonial_id', 'aspect_id']);
                $table->index('aspect_id');
                $table->foreign('testimonial_id')->references('id')->on('testimonials')->cascadeOnDelete();
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('testimonial_ratings');
        $schema->dropIfExists('testimonials');
    },
];
