<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('saveable.saves_table', 'saves'), function (Blueprint $table) {
            $table->id();
            $table->morphs('saver');
            $table->morphs('saveable');
            $table->unsignedBigInteger('collection_id')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('order_column')->default(0);
            $table->timestamps();

            // Unique constraint: one save per saver-saveable combination
            $table->unique(['saver_type', 'saver_id', 'saveable_type', 'saveable_id'], 'unique_save');

            $table->index('collection_id');
            $table->index('order_column');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('saveable.saves_table', 'saves'));
    }
};
