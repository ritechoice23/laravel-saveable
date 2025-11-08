<?php

use Ritechoice23\Saveable\Models\Save;
use Ritechoice23\Saveable\Tests\Models\Comment;
use Ritechoice23\Saveable\Tests\Models\Post;
use Ritechoice23\Saveable\Tests\Models\Team;
use Ritechoice23\Saveable\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $this->user2 = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $this->post = Post::create(['title' => 'Test Post', 'content' => 'Test Content']);
});

describe('Save Records Relationship', function () {
    test('saveable has save records relationship', function () {
        $this->user->saveItem($this->post);

        $saveRecords = $this->post->saveRecords;

        expect($saveRecords)->toHaveCount(1)
            ->and($saveRecords->first())->toBeInstanceOf(Save::class)
            ->and($saveRecords->first()->saver_id)->toBe($this->user->id);
    });

    test('times saved returns correct count', function () {
        expect($this->post->timesSaved())->toBe(0);

        $this->user->saveItem($this->post);
        expect($this->post->timesSaved())->toBe(1);

        $this->user2->saveItem($this->post);
        expect($this->post->timesSaved())->toBe(2);
    });

    test('is saved by returns correct boolean', function () {
        expect($this->post->isSavedBy($this->user))->toBeFalse();

        $this->user->saveItem($this->post);

        expect($this->post->isSavedBy($this->user))->toBeTrue()
            ->and($this->post->isSavedBy($this->user2))->toBeFalse();
    });

    test('saved record by returns correct save record', function () {
        $this->user->saveItem($this->post);

        $save = $this->post->savedRecordBy($this->user);

        expect($save)->toBeInstanceOf(Save::class)
            ->and($save->saver_id)->toBe($this->user->id)
            ->and($save->saveable_id)->toBe($this->post->id);
    });

    test('saved record by returns null when not saved', function () {
        $save = $this->post->savedRecordBy($this->user);

        expect($save)->toBeNull();
    });
});

describe('Savers Query', function () {
    test('can get savers of single type', function () {
        $team = Team::create(['name' => 'Test Team']);

        $this->user->saveItem($this->post);
        $team->saveItem($this->post);

        $userSavers = $this->post->savers(User::class)->get();

        expect($userSavers)->toHaveCount(1)
            ->and($userSavers->first())->toBeInstanceOf(User::class)
            ->and($userSavers->first()->id)->toBe($this->user->id);
    });

    test('can chain queries on savers', function () {
        $user3 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->user->saveItem($this->post);
        $this->user2->saveItem($this->post);
        $user3->saveItem($this->post);

        $savers = $this->post->savers(User::class)
            ->where('name', 'like', '%Doe')
            ->get();

        expect($savers)->toHaveCount(2);
    });

    test('can get savers grouped by type', function () {
        $team = Team::create(['name' => 'Test Team']);

        $this->user->saveItem($this->post);
        $team->saveItem($this->post);

        $grouped = $this->post->saversGrouped();

        expect($grouped)->toHaveCount(2)
            ->and($grouped->has(User::class))->toBeTrue()
            ->and($grouped->has(Team::class))->toBeTrue()
            ->and($grouped->get(User::class))->toHaveCount(1)
            ->and($grouped->get(Team::class))->toHaveCount(1);
    });

    test('savers count returns correct count', function () {
        $team = Team::create(['name' => 'Test Team']);

        $this->user->saveItem($this->post);
        $this->user2->saveItem($this->post);
        $team->saveItem($this->post);

        expect($this->post->saversCount())->toBe(3)
            ->and($this->post->saversCount(User::class))->toBe(2)
            ->and($this->post->saversCount(Team::class))->toBe(1);
    });
});

describe('Remove Saves', function () {
    test('can remove saved by specific saver', function () {
        $this->user->saveItem($this->post);
        $this->user2->saveItem($this->post);

        $result = $this->post->removeSavedBy($this->user);

        expect($result)->toBeTrue()
            ->and($this->post->isSavedBy($this->user))->toBeFalse()
            ->and($this->post->isSavedBy($this->user2))->toBeTrue()
            ->and($this->post->timesSaved())->toBe(1);
    });

    test('removing non-existent save returns false', function () {
        $result = $this->post->removeSavedBy($this->user);

        expect($result)->toBeFalse();
    });
});

describe('Query Scopes', function () {
    test('with saves count scope adds count', function () {
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);

        $this->user->saveItem($this->post);
        $this->user2->saveItem($this->post);
        $this->user->saveItem($post2);

        $posts = Post::withSavesCount()->get();

        $postWithTwoSaves = $posts->firstWhere('id', $this->post->id);
        $postWithOneSave = $posts->firstWhere('id', $post2->id);

        expect($postWithTwoSaves->saves_count)->toBe(2)
            ->and($postWithOneSave->saves_count)->toBe(1);
    });

    test('most saved scope orders by save count', function () {
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);
        $post3 = Post::create(['title' => 'Post 3', 'content' => 'Content']);

        $this->user->saveItem($this->post);
        $this->user2->saveItem($this->post);
        $this->user->saveItem($post2);

        $mostSaved = Post::mostSaved(2)->get();

        expect($mostSaved)->toHaveCount(2)
            ->and($mostSaved->first()->id)->toBe($this->post->id)
            ->and($mostSaved->last()->id)->toBe($post2->id);
    });

    test('with save status scope adds save status for user', function () {
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);

        $this->user->saveItem($this->post, null, ['note' => 'Important']);

        $posts = Post::withSaveStatus($this->user)->get();

        $savedPost = $posts->firstWhere('id', $this->post->id);
        $unsavedPost = $posts->firstWhere('id', $post2->id);

        expect((bool) $savedPost->is_saved)->toBeTrue()
            ->and(json_decode($savedPost->save_metadata, true))->toBe(['note' => 'Important'])
            ->and((bool) $unsavedPost->is_saved)->toBeFalse();
    });

    test('where saved by scope filters by saver', function () {
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content']);

        $this->user->saveItem($this->post);
        $this->user2->saveItem($post2);

        $userPosts = Post::whereSavedBy($this->user)->get();

        expect($userPosts)->toHaveCount(1)
            ->and($userPosts->first()->id)->toBe($this->post->id);
    });
});

describe('Multiple Saveable Types', function () {
    test('different models can be saved independently', function () {
        $comment = Comment::create(['body' => 'Test Comment']);

        $this->user->saveItem($this->post);
        $this->user->saveItem($comment);

        expect($this->post->timesSaved())->toBe(1)
            ->and($comment->timesSaved())->toBe(1)
            ->and(Save::count())->toBe(2);
    });

    test('savers can be different model types', function () {
        $team = Team::create(['name' => 'Test Team']);

        $this->user->saveItem($this->post);
        $team->saveItem($this->post);

        $grouped = $this->post->saversGrouped();

        expect($grouped)->toHaveCount(2)
            ->and($grouped->has(User::class))->toBeTrue()
            ->and($grouped->has(Team::class))->toBeTrue();
    });
});

describe('Edge Cases', function () {
    test('multiple savers can save same item', function () {
        $this->user->saveItem($this->post);
        $this->user2->saveItem($this->post);

        expect($this->post->timesSaved())->toBe(2);
    });

    test('deleting saver removes saves', function () {
        $this->user->saveItem($this->post);

        expect(Save::count())->toBe(1);

        $this->user->delete();

        expect(Save::count())->toBe(0);
    });

    test('deleting saveable removes saves', function () {
        $this->user->saveItem($this->post);

        expect(Save::count())->toBe(1);

        $this->post->delete();

        expect(Save::count())->toBe(0);
    });
});
