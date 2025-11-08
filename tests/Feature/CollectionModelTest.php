<?php

use Ritechoice23\Saveable\Models\Collection;
use Ritechoice23\Saveable\Models\Save;
use Ritechoice23\Saveable\Tests\Models\Comment;
use Ritechoice23\Saveable\Tests\Models\Post;
use Ritechoice23\Saveable\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $this->post = Post::create(['title' => 'Test Post', 'content' => 'Test Content']);
});

describe('Collection Model Basics', function () {
    test('can create a collection', function () {
        $collection = Collection::create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'name' => 'Reading List',
            'description' => 'My reading list',
        ]);

        expect($collection)->toBeInstanceOf(Collection::class)
            ->and($collection->name)->toBe('Reading List')
            ->and($collection->description)->toBe('My reading list')
            ->and($collection->owner_type)->toBe(User::class)
            ->and($collection->owner_id)->toBe($this->user->id);
    });

    test('can create collection without description', function () {
        $collection = Collection::create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'name' => 'Reading List',
        ]);

        expect($collection->description)->toBeNull();
    });
});

describe('Collection Relationships', function () {
    test('collection has owner relationship', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);

        expect($collection->owner)->toBeInstanceOf(User::class)
            ->and($collection->owner->id)->toBe($this->user->id);
    });

    test('collection has saves relationship', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);

        $this->user->saveItem($this->post, $collection);

        $saves = $collection->saves;

        expect($saves)->toHaveCount(1)
            ->and($saves->first())->toBeInstanceOf(Save::class);
    });

    test('collection has parent relationship', function () {
        $parent = $this->user->collections()->create(['name' => 'Parent']);
        $child = $this->user->collections()->create([
            'name' => 'Child',
            'parent_id' => $parent->id,
        ]);

        expect($child->parent)->toBeInstanceOf(Collection::class)
            ->and($child->parent->id)->toBe($parent->id);
    });

    test('collection has children relationship', function () {
        $parent = $this->user->collections()->create(['name' => 'Parent']);
        $child1 = $this->user->collections()->create([
            'name' => 'Child 1',
            'parent_id' => $parent->id,
        ]);
        $child2 = $this->user->collections()->create([
            'name' => 'Child 2',
            'parent_id' => $parent->id,
        ]);

        $children = $parent->children;

        expect($children)->toHaveCount(2);
    });

    test('root collection has null parent', function () {
        $collection = $this->user->collections()->create(['name' => 'Root']);

        expect($collection->parent)->toBeNull()
            ->and($collection->parent_id)->toBeNull();
    });
});

describe('Collection Items', function () {
    test('items method returns saved models', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);
        $comment = Comment::create(['body' => 'Test Comment']);

        $this->user->saveItem($this->post, $collection);
        $this->user->saveItem($comment, $collection);

        $items = $collection->items();

        expect($items)->toHaveCount(2)
            ->and($items->pluck('id')->contains($this->post->id))->toBeTrue()
            ->and($items->pluck('id')->contains($comment->id))->toBeTrue();
    });

    test('items method returns empty collection when no items', function () {
        $collection = $this->user->collections()->create(['name' => 'Empty']);

        $items = $collection->items();

        expect($items)->toHaveCount(0);
    });

    test('items are ordered by order_column', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);
        $post1 = Post::create(['title' => 'Post 1', 'content' => 'Content']);
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);

        $this->user->saveItem($post1, $collection);
        $this->user->saveItem($post2, $collection);

        $items = $collection->items();

        expect($items->first()->id)->toBe($post1->id)
            ->and($items->last()->id)->toBe($post2->id);
    });
});

describe('Collection Scopes', function () {
    test('by owner scope filters by owner', function () {
        $user2 = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->user->collections()->create(['name' => 'User 1 Collection']);
        $user2->collections()->create(['name' => 'User 2 Collection']);

        $collections = Collection::byOwner($this->user)->get();

        expect($collections)->toHaveCount(1)
            ->and($collections->first()->owner_id)->toBe($this->user->id);
    });

    test('root only scope filters root collections', function () {
        $parent = $this->user->collections()->create(['name' => 'Parent']);
        $this->user->collections()->create(['name' => 'Child', 'parent_id' => $parent->id]);

        $rootCollections = Collection::rootOnly()->get();

        expect($rootCollections)->toHaveCount(1)
            ->and($rootCollections->first()->name)->toBe('Parent');
    });

    test('by parent scope filters by parent', function () {
        $parent = $this->user->collections()->create(['name' => 'Parent']);
        $child1 = $this->user->collections()->create(['name' => 'Child 1', 'parent_id' => $parent->id]);
        $child2 = $this->user->collections()->create(['name' => 'Child 2', 'parent_id' => $parent->id]);
        $this->user->collections()->create(['name' => 'Other Child']);

        $children = Collection::byParent($parent)->get();

        expect($children)->toHaveCount(2);
    });
});

describe('Nested Collections', function () {
    test('can create nested collection hierarchy', function () {
        $level1 = $this->user->collections()->create(['name' => 'Level 1']);
        $level2 = $this->user->collections()->create(['name' => 'Level 2', 'parent_id' => $level1->id]);
        $level3 = $this->user->collections()->create(['name' => 'Level 3', 'parent_id' => $level2->id]);

        expect($level3->parent->id)->toBe($level2->id)
            ->and($level3->parent->parent->id)->toBe($level1->id);
    });

    test('deleting parent deletes children', function () {
        $parent = $this->user->collections()->create(['name' => 'Parent']);
        $this->user->collections()->create(['name' => 'Child', 'parent_id' => $parent->id]);

        expect(Collection::count())->toBe(2);

        $parent->delete();

        expect(Collection::count())->toBe(0);
    });

    test('deleting parent sets saves collection_id to null', function () {
        $parent = $this->user->collections()->create(['name' => 'Parent']);
        $this->user->saveItem($this->post, $parent);

        $save = Save::first();
        expect($save->collection_id)->toBe($parent->id);

        $parent->delete();

        $save->refresh();
        expect($save->collection_id)->toBeNull();
    });
});

describe('Collection with Multiple Saves', function () {
    test('can have saves from different savers', function () {
        $user2 = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $collection = $this->user->collections()->create(['name' => 'Shared Collection']);

        $this->user->saveItem($this->post, $collection);
        $user2->saveItem($this->post, $collection);

        $saves = $collection->saves;

        expect($saves)->toHaveCount(2);
    });

    test('can have different model types in same collection', function () {
        $collection = $this->user->collections()->create(['name' => 'Mixed Collection']);
        $comment = Comment::create(['body' => 'Test Comment']);

        $this->user->saveItem($this->post, $collection);
        $this->user->saveItem($comment, $collection);

        $items = $collection->items();

        expect($items)->toHaveCount(2)
            ->and($items->whereInstanceOf(Post::class))->toHaveCount(1)
            ->and($items->whereInstanceOf(Comment::class))->toHaveCount(1);
    });
});

describe('Cascading Effects', function () {
    test('deleting owner deletes collections', function () {
        $this->user->collections()->create(['name' => 'Collection 1']);
        $this->user->collections()->create(['name' => 'Collection 2']);

        expect(Collection::count())->toBe(2);

        $this->user->delete();

        expect(Collection::count())->toBe(0);
    });

    test('deleting collection does not delete saved items', function () {
        $collection = $this->user->collections()->create(['name' => 'Collection']);
        $this->user->saveItem($this->post, $collection);

        expect(Post::count())->toBe(1);

        $collection->delete();

        expect(Post::count())->toBe(1);
    });

    test('deleting collection removes association from saves', function () {
        $collection = $this->user->collections()->create(['name' => 'Collection']);
        $this->user->saveItem($this->post, $collection);

        $save = Save::first();
        expect($save->collection_id)->toBe($collection->id);

        $collection->delete();

        $save->refresh();
        expect($save->collection_id)->toBeNull();
    });
});
