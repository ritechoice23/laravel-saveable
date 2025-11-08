<?php

use Ritechoice23\Saveable\Models\Collection;
use Ritechoice23\Saveable\Models\Save;
use Ritechoice23\Saveable\Tests\Models\Post;
use Ritechoice23\Saveable\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $this->post = Post::create(['title' => 'Test Post', 'content' => 'Test Content']);
});

describe('Save Model Basics', function () {
    test('can create a save record', function () {
        $save = Save::create([
            'saver_type' => User::class,
            'saver_id' => $this->user->id,
            'saveable_type' => Post::class,
            'saveable_id' => $this->post->id,
            'metadata' => ['note' => 'Test'],
            'order_column' => 1,
        ]);

        expect($save)->toBeInstanceOf(Save::class)
            ->and($save->saver_type)->toBe(User::class)
            ->and($save->saveable_type)->toBe(Post::class)
            ->and($save->metadata)->toBe(['note' => 'Test'])
            ->and($save->order_column)->toBe(1);
    });

    test('metadata is cast to array', function () {
        $save = Save::create([
            'saver_type' => User::class,
            'saver_id' => $this->user->id,
            'saveable_type' => Post::class,
            'saveable_id' => $this->post->id,
            'metadata' => ['key' => 'value'],
        ]);

        expect($save->metadata)->toBeArray()
            ->and($save->metadata['key'])->toBe('value');
    });

    test('can store null metadata', function () {
        $save = Save::create([
            'saver_type' => User::class,
            'saver_id' => $this->user->id,
            'saveable_type' => Post::class,
            'saveable_id' => $this->post->id,
            'metadata' => null,
        ]);

        expect($save->metadata)->toBeNull();
    });
});

describe('Save Relationships', function () {
    test('save has saver relationship', function () {
        $this->user->saveItem($this->post);

        $save = Save::first();

        expect($save->saver)->toBeInstanceOf(User::class)
            ->and($save->saver->id)->toBe($this->user->id);
    });

    test('save has saveable relationship', function () {
        $this->user->saveItem($this->post);

        $save = Save::first();

        expect($save->saveable)->toBeInstanceOf(Post::class)
            ->and($save->saveable->id)->toBe($this->post->id);
    });

    test('save has collection relationship', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);
        $this->user->saveItem($this->post, $collection);

        $save = Save::first();

        expect($save->collection)->toBeInstanceOf(Collection::class)
            ->and($save->collection->id)->toBe($collection->id);
    });

    test('save without collection has null relationship', function () {
        $this->user->saveItem($this->post);

        $save = Save::first();

        expect($save->collection)->toBeNull();
    });
});

describe('Save Scopes', function () {
    test('by saver scope filters by saver', function () {
        $user2 = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->user->saveItem($this->post);
        $user2->saveItem($this->post);

        $saves = Save::bySaver($this->user)->get();

        expect($saves)->toHaveCount(1)
            ->and($saves->first()->saver_id)->toBe($this->user->id);
    });

    test('by saveable scope filters by saveable', function () {
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);

        $this->user->saveItem($this->post);
        $this->user->saveItem($post2);

        $saves = Save::bySaveable($this->post)->get();

        expect($saves)->toHaveCount(1)
            ->and($saves->first()->saveable_id)->toBe($this->post->id);
    });

    test('by collection scope filters by collection', function () {
        $collection1 = $this->user->collections()->create(['name' => 'Collection 1']);
        $collection2 = $this->user->collections()->create(['name' => 'Collection 2']);

        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);

        $this->user->saveItem($this->post, $collection1);
        $this->user->saveItem($post2, $collection2);

        $saves = Save::byCollection($collection1)->get();

        expect($saves)->toHaveCount(1)
            ->and($saves->first()->saveable_id)->toBe($this->post->id);
    });

    test('where saveable type scope filters by type', function () {
        $this->user->saveItem($this->post);

        $saves = Save::whereSaveableType(Post::class)->get();

        expect($saves)->toHaveCount(1)
            ->and($saves->first()->saveable_type)->toBe(Post::class);
    });

    test('ordered scope orders by order_column', function () {
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);
        $post3 = Post::create(['title' => 'Post 3', 'content' => 'Content']);

        $this->user->saveItem($post3);
        $this->user->saveItem($post2);
        $this->user->saveItem($this->post);

        $saves = Save::ordered()->get();

        expect($saves[0]->order_column)->toBeLessThanOrEqual($saves[1]->order_column)
            ->and($saves[1]->order_column)->toBeLessThanOrEqual($saves[2]->order_column);
    });
});

describe('Unique Constraints', function () {
    test('cannot create duplicate save for same saver and saveable', function () {
        Save::create([
            'saver_type' => User::class,
            'saver_id' => $this->user->id,
            'saveable_type' => Post::class,
            'saveable_id' => $this->post->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Save::create([
            'saver_type' => User::class,
            'saver_id' => $this->user->id,
            'saveable_type' => Post::class,
            'saveable_id' => $this->post->id,
        ]);
    });
});

describe('Cascading Deletes', function () {
    test('deleting saver deletes save records', function () {
        $this->user->saveItem($this->post);

        expect(Save::count())->toBe(1);

        $this->user->delete();

        expect(Save::count())->toBe(0);
    });

    test('deleting saveable deletes save records', function () {
        $this->user->saveItem($this->post);

        expect(Save::count())->toBe(1);

        $this->post->delete();

        expect(Save::count())->toBe(0);
    });

    test('deleting collection sets collection_id to null', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);
        $this->user->saveItem($this->post, $collection);

        $save = Save::first();
        expect($save->collection_id)->toBe($collection->id);

        $collection->delete();

        $save->refresh();
        expect($save->collection_id)->toBeNull();
    });
});
