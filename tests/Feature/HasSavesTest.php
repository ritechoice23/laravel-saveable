<?php

use Illuminate\Support\Facades\Config;
use Ritechoice23\Saveable\Models\Collection;
use Ritechoice23\Saveable\Models\Save;
use Ritechoice23\Saveable\Tests\Models\Comment;
use Ritechoice23\Saveable\Tests\Models\Post;
use Ritechoice23\Saveable\Tests\Models\Team;
use Ritechoice23\Saveable\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $this->post = Post::create(['title' => 'Test Post', 'content' => 'Test Content']);
    $this->comment = Comment::create(['body' => 'Test Comment']);
});

describe('Basic Save Operations', function () {
    test('user can save an item', function () {
        $result = $this->user->saveItem($this->post);

        expect($result)->toBeTrue()
            ->and($this->user->hasSavedItem($this->post))->toBeTrue()
            ->and(Save::count())->toBe(1);
    });

    test('user cannot save the same item twice', function () {
        $this->user->saveItem($this->post);
        $result = $this->user->saveItem($this->post);

        expect($result)->toBeFalse()
            ->and(Save::count())->toBe(1);
    });

    test('user can unsave an item', function () {
        $this->user->saveItem($this->post);
        $result = $this->user->unsaveItem($this->post);

        expect($result)->toBeTrue()
            ->and($this->user->hasSavedItem($this->post))->toBeFalse()
            ->and(Save::count())->toBe(0);
    });

    test('unsaving non-saved item returns false', function () {
        $result = $this->user->unsaveItem($this->post);

        expect($result)->toBeFalse();
    });

    test('user can toggle save item', function () {
        // Toggle on
        $result = $this->user->toggleSaveItem($this->post);
        expect($result)->toBeTrue()
            ->and($this->user->hasSavedItem($this->post))->toBeTrue();

        // Toggle off
        $result = $this->user->toggleSaveItem($this->post);
        expect($result)->toBeFalse()
            ->and($this->user->hasSavedItem($this->post))->toBeFalse();
    });

    test('user can check if item is saved', function () {
        expect($this->user->hasSavedItem($this->post))->toBeFalse();

        $this->user->saveItem($this->post);

        expect($this->user->hasSavedItem($this->post))->toBeTrue();
    });
});

describe('Save Records & Relationships', function () {
    test('user can get saved record', function () {
        $this->user->saveItem($this->post);
        $save = $this->user->getSavedRecord($this->post);

        expect($save)->toBeInstanceOf(Save::class)
            ->and($save->saveable_id)->toBe($this->post->id)
            ->and($save->saveable_type)->toBe(Post::class);
    });

    test('get saved record returns null for unsaved item', function () {
        $save = $this->user->getSavedRecord($this->post);

        expect($save)->toBeNull();
    });

    test('saved records relationship works', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $savedRecords = $this->user->savedRecords;

        expect($savedRecords)->toHaveCount(2)
            ->and($savedRecords->first())->toBeInstanceOf(Save::class);
    });

    test('saved records are ordered by order_column', function () {
        $post1 = Post::create(['title' => 'Post 1', 'content' => 'Content 1']);
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content 2']);
        $post3 = Post::create(['title' => 'Post 3', 'content' => 'Content 3']);

        $this->user->saveItem($post1);
        $this->user->saveItem($post2);
        $this->user->saveItem($post3);

        $savedRecords = $this->user->savedRecords()->get();

        expect($savedRecords[0]->order_column)->toBe(1)
            ->and($savedRecords[1]->order_column)->toBe(2)
            ->and($savedRecords[2]->order_column)->toBe(3);
    });
});

describe('Saved Items Query', function () {
    test('can get saved items of single type', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $savedPosts = $this->user->savedItems(Post::class)->get();

        expect($savedPosts)->toHaveCount(1)
            ->and($savedPosts->first())->toBeInstanceOf(Post::class)
            ->and($savedPosts->first()->id)->toBe($this->post->id);
    });

    test('can chain queries on saved items', function () {
        $post1 = Post::create(['title' => 'Published Post', 'content' => 'Content', 'published' => true]);
        $post2 = Post::create(['title' => 'Draft Post', 'content' => 'Content', 'published' => false]);

        $this->user->saveItem($post1);
        $this->user->saveItem($post2);

        $publishedPosts = $this->user->savedItems(Post::class)
            ->where('published', true)
            ->get();

        expect($publishedPosts)->toHaveCount(1)
            ->and($publishedPosts->first()->id)->toBe($post1->id);
    });

    test('saved items of type works', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $savedComments = $this->user->savedItemsOfType(Comment::class)->get();

        expect($savedComments)->toHaveCount(1)
            ->and($savedComments->first())->toBeInstanceOf(Comment::class);
    });

    test('can get saved items grouped by type', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $grouped = $this->user->savedItemsGrouped();

        expect($grouped)->toHaveCount(2)
            ->and($grouped->has(Post::class))->toBeTrue()
            ->and($grouped->has(Comment::class))->toBeTrue()
            ->and($grouped->get(Post::class))->toHaveCount(1)
            ->and($grouped->get(Comment::class))->toHaveCount(1);
    });

    test('saved items count returns correct count', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        expect($this->user->savedItemsCount())->toBe(2)
            ->and($this->user->savedItemsCount(Post::class))->toBe(1)
            ->and($this->user->savedItemsCount(Comment::class))->toBe(1);
    });
});

describe('Collections', function () {
    test('user can create a collection', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);

        expect($collection)->toBeInstanceOf(Collection::class)
            ->and($collection->name)->toBe('Reading List')
            ->and($collection->owner_id)->toBe($this->user->id);
    });

    test('user can get all collections', function () {
        $this->user->collections()->create(['name' => 'Collection 1']);
        $this->user->collections()->create(['name' => 'Collection 2']);

        $collections = $this->user->collections;

        expect($collections)->toHaveCount(2);
    });

    test('user can get root collections only', function () {
        $parent = $this->user->collections()->create(['name' => 'Parent']);
        $this->user->collections()->create(['name' => 'Child', 'parent_id' => $parent->id]);

        $rootCollections = $this->user->rootCollections()->get();

        expect($rootCollections)->toHaveCount(1)
            ->and($rootCollections->first()->name)->toBe('Parent');
    });

    test('can save item to collection', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);

        $this->user->saveItem($this->post, $collection);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->collection_id)->toBe($collection->id);
    });

    test('can move saved item to different collection', function () {
        $collection1 = $this->user->collections()->create(['name' => 'Collection 1']);
        $collection2 = $this->user->collections()->create(['name' => 'Collection 2']);

        $this->user->saveItem($this->post, $collection1);
        $this->user->moveSavedItem($this->post, $collection2);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->collection_id)->toBe($collection2->id);
    });

    test('can move saved item to root (no collection)', function () {
        $collection = $this->user->collections()->create(['name' => 'Collection']);

        $this->user->saveItem($this->post, $collection);
        $this->user->moveSavedItem($this->post, null);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->collection_id)->toBeNull();
    });

    test('moving unsaved item returns false', function () {
        $collection = $this->user->collections()->create(['name' => 'Collection']);

        $result = $this->user->moveSavedItem($this->post, $collection);

        expect($result)->toBeFalse();
    });

    test('can get unsorted saved items', function () {
        $collection = $this->user->collections()->create(['name' => 'Collection']);

        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment, $collection);

        $unsorted = $this->user->unsortedSavedRecords();

        expect($unsorted)->toHaveCount(1)
            ->and($unsorted->first()->saveable_id)->toBe($this->post->id);
    });

    test('can get unsorted saved items of specific type', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $unsortedPosts = $this->user->unsortedSavedItems(Post::class)->get();

        expect($unsortedPosts)->toHaveCount(1)
            ->and($unsortedPosts->first())->toBeInstanceOf(Post::class);
    });
});

describe('Metadata', function () {
    test('can save item with metadata', function () {
        $metadata = ['note' => 'Read later', 'priority' => 'high'];

        $this->user->saveItem($this->post, null, $metadata);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->metadata)->toBe($metadata)
            ->and($save->metadata['note'])->toBe('Read later')
            ->and($save->metadata['priority'])->toBe('high');
    });

    test('can update metadata on saved item', function () {
        $this->user->saveItem($this->post, null, ['note' => 'Initial note']);

        $this->user->updateSavedItemMetadata($this->post, ['note' => 'Updated note', 'priority' => 'low']);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->metadata['note'])->toBe('Updated note')
            ->and($save->metadata['priority'])->toBe('low');
    });

    test('updating metadata on unsaved item returns false', function () {
        $result = $this->user->updateSavedItemMetadata($this->post, ['note' => 'Test']);

        expect($result)->toBeFalse();
    });

    test('can toggle save with metadata', function () {
        $metadata = ['note' => 'Important'];

        $this->user->toggleSaveItem($this->post, null, $metadata);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->metadata['note'])->toBe('Important');
    });
});

describe('Ordering', function () {
    test('auto ordering assigns sequential order numbers', function () {
        Config::set('saveable.auto_ordering', true);

        $post1 = Post::create(['title' => 'Post 1', 'content' => 'Content']);
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);
        $post3 = Post::create(['title' => 'Post 3', 'content' => 'Content']);

        $this->user->saveItem($post1);
        $this->user->saveItem($post2);
        $this->user->saveItem($post3);

        $saves = $this->user->savedRecords()->get();

        expect($saves[0]->order_column)->toBe(1)
            ->and($saves[1]->order_column)->toBe(2)
            ->and($saves[2]->order_column)->toBe(3);
    });

    test('order numbers are scoped by collection', function () {
        $collection1 = $this->user->collections()->create(['name' => 'Collection 1']);
        $collection2 = $this->user->collections()->create(['name' => 'Collection 2']);

        $post1 = Post::create(['title' => 'Post 1', 'content' => 'Content']);
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);

        $this->user->saveItem($post1, $collection1);
        $this->user->saveItem($post2, $collection2);

        $save1 = Save::where('collection_id', $collection1->id)->first();
        $save2 = Save::where('collection_id', $collection2->id)->first();

        expect($save1->order_column)->toBe(1)
            ->and($save2->order_column)->toBe(1);
    });

    test('can disable auto ordering', function () {
        Config::set('saveable.auto_ordering', false);

        $this->user->saveItem($this->post);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->order_column)->toBe(0);
    });
});

describe('Scopes', function () {
    test('where saved item scope filters users who saved an item', function () {
        $user2 = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->user->saveItem($this->post);

        $users = User::whereSavedItem($this->post)->get();

        expect($users)->toHaveCount(1)
            ->and($users->first()->id)->toBe($this->user->id);
    });
});

describe('Multiple Model Types', function () {
    test('can save multiple different model types', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        expect($this->user->savedItemsCount())->toBe(2)
            ->and($this->user->savedItemsCount(Post::class))->toBe(1)
            ->and($this->user->savedItemsCount(Comment::class))->toBe(1);
    });

    test('different model types can save using HasSaves trait', function () {
        $team = Team::create(['name' => 'Test Team']);

        $team->saveItem($this->post);

        expect($team->hasSavedItem($this->post))->toBeTrue()
            ->and(Save::count())->toBe(1);
    });
});
