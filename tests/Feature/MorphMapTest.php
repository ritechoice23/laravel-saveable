<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Ritechoice23\Saveable\Tests\Models\Comment;
use Ritechoice23\Saveable\Tests\Models\Post;
use Ritechoice23\Saveable\Tests\Models\User;

beforeEach(function () {
    // Set up morph map
    Relation::morphMap([
        'user' => User::class,
        'post' => Post::class,
        'comment' => Comment::class,
    ]);

    $this->user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $this->post = Post::create(['title' => 'Test Post', 'content' => 'Test Content']);
    $this->comment = Comment::create(['body' => 'Test Comment']);
});

afterEach(function () {
    // Clear morph map after each test
    Relation::morphMap([], false);
});

describe('MorphMap Support', function () {
    test('can save item with morphMap configured', function () {
        $result = $this->user->saveItem($this->post);

        expect($result)->toBeTrue()
            ->and($this->user->hasSavedItem($this->post))->toBeTrue();
    });

    test('savedItems returns results with morphMap', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $savedPosts = $this->user->savedItems(Post::class)->get();

        expect($savedPosts)->toHaveCount(1)
            ->and($savedPosts->first()->id)->toBe($this->post->id);
    });

    test('savedItems without type parameter works with morphMap', function () {
        $this->user->saveItem($this->post);

        $savedItems = $this->user->savedItems()->get();

        expect($savedItems)->toHaveCount(1)
            ->and($savedItems->first()->id)->toBe($this->post->id);
    });

    test('savedItemsGrouped works with morphMap', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $grouped = $this->user->savedItemsGrouped();

        // Check using morph aliases
        expect($grouped->has('post'))->toBeTrue()
            ->and($grouped->has('comment'))->toBeTrue()
            ->and($grouped->get('post'))->toHaveCount(1)
            ->and($grouped->get('comment'))->toHaveCount(1);
    });

    test('savedItemsCount works with morphMap', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $totalCount = $this->user->savedItemsCount();
        $postCount = $this->user->savedItemsCount(Post::class);
        $commentCount = $this->user->savedItemsCount(Comment::class);

        expect($totalCount)->toBe(2)
            ->and($postCount)->toBe(1)
            ->and($commentCount)->toBe(1);
    });

    test('unsavedItems works with morphMap', function () {
        $this->user->saveItem($this->post);
        $this->user->saveItem($this->comment);

        $unsortedPosts = $this->user->unsortedSavedItems(Post::class)->get();

        expect($unsortedPosts)->toHaveCount(1)
            ->and($unsortedPosts->first()->id)->toBe($this->post->id);
    });

    test('savers method works with morphMap', function () {
        $user2 = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->user->saveItem($this->post);
        $user2->saveItem($this->post);

        $savers = $this->post->savers(User::class)->get();

        expect($savers)->toHaveCount(2);
    });

    test('saversCount works with morphMap', function () {
        $user2 = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->user->saveItem($this->post);
        $user2->saveItem($this->post);

        $count = $this->post->saversCount(User::class);

        expect($count)->toBe(2);
    });

    test('saversGrouped works with morphMap', function () {
        $this->user->saveItem($this->post);

        $grouped = $this->post->saversGrouped();

        expect($grouped->has('user'))->toBeTrue()
            ->and($grouped->get('user'))->toHaveCount(1);
    });

    test('can chain queries on savedItems with morphMap', function () {
        $post1 = Post::create(['title' => 'Published', 'content' => 'Content', 'published' => true]);
        $post2 = Post::create(['title' => 'Draft', 'content' => 'Content', 'published' => false]);

        $this->user->saveItem($post1);
        $this->user->saveItem($post2);

        $publishedPosts = $this->user->savedItems(Post::class)
            ->where('published', true)
            ->get();

        expect($publishedPosts)->toHaveCount(1)
            ->and($publishedPosts->first()->id)->toBe($post1->id);
    });

    test('collections work with morphMap', function () {
        $collection = $this->user->collections()->create(['name' => 'Reading List']);

        $this->user->saveItem($this->post, $collection);

        $saves = $collection->saves;

        expect($saves)->toHaveCount(1)
            ->and($saves->first()->saveable_id)->toBe($this->post->id);
    });

    test('moving items between collections works with morphMap', function () {
        $collection1 = $this->user->collections()->create(['name' => 'Collection 1']);
        $collection2 = $this->user->collections()->create(['name' => 'Collection 2']);

        $this->user->saveItem($this->post, $collection1);
        $this->user->moveSavedItem($this->post, $collection2);

        $save = $this->user->getSavedRecord($this->post);

        expect($save->collection_id)->toBe($collection2->id);
    });
});
