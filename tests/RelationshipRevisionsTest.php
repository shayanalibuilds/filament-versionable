<?php

use Filament\Resources\Events\RecordSaved;
use Illuminate\Support\Facades\Event;
use Mansoor\FilamentVersionable\Support\RelationSnapshotManager;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\Attachment;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\Category;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\Comment;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\Post;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\Project;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\SeoMeta;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\Tag;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\User;
use Mansoor\FilamentVersionable\Tests\Fixtures\Models\VersionablePost;
use Mansoor\FilamentVersionable\Tests\Fixtures\Resources\PostResource\Pages\PostRevisions;
use Mansoor\FilamentVersionable\Tests\Fixtures\Resources\TestRecordSavedPage;
use Mansoor\FilamentVersionable\Tests\Fixtures\Resources\VersionablePostResource\Pages\VersionablePostRevisions;

beforeEach(function () {
    $this->user = createUser();
    $this->actingAs($this->user);

    RelationSnapshotManager::flushRuntimeState();
});

function createVersionablePost(array $attributes = []): VersionablePost
{
    return VersionablePost::create(array_merge([
        'title' => 'Post Title',
        'content' => 'Post Content',
        'user_id' => User::query()->first()?->getKey() ?? createUser()->getKey(),
    ], $attributes));
}

function seedVersionablePostRelations(VersionablePost $post): void
{
    $post->comments()->createMany([
        ['author' => 'Alice', 'body' => 'First comment'],
        ['author' => 'Bob', 'body' => 'Second comment'],
    ]);

    $post->profile()->create(['bio' => 'Original bio']);

    $attachment = new Attachment(['name' => 'contract.pdf']);
    $attachment->attachable()->associate($post);
    $attachment->save();

    $seoMeta = new SeoMeta(['meta' => 'original meta']);
    $seoMeta->metaable()->associate($post);
    $seoMeta->save();

    $tags = collect([
        Tag::create(['name' => 'Laravel']),
        Tag::create(['name' => 'Filament']),
    ]);

    foreach ($tags as $index => $tag) {
        $post->tags()->attach($tag->getKey(), ['position' => $index + 1]);
        $post->featuredTags()->attach($tag->getKey(), ['position' => $index + 1]);
    }
}

function dispatchRecordSaved(VersionablePost $post): void
{
    // Mirror how Filament's resource pages fire the event (class-style payload)
    Event::dispatch(RecordSaved::class, ['record' => $post, 'data' => [], 'page' => new TestRecordSavedPage]);
}

function relationSnapshot(VersionablePost $post): array
{
    return RelationSnapshotManager::forVersion($post->latestVersion()->first());
}

describe('snapshot capture', function () {
    it('stores an empty snapshot on the initial version created before relations exist', function () {
        $post = createVersionablePost();

        $snapshot = relationSnapshot($post);

        expect($snapshot)->toHaveKey('comments')
            ->and($snapshot['comments']['type'])->toBe('hasMany')
            ->and($snapshot['comments']['records'])->toBe([]);
    });

    it('refreshes the initial version snapshot when Filament saves relationships', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);

        // Filament fires RecordSaved after relationships are persisted
        dispatchRecordSaved($post);

        $snapshot = relationSnapshot($post);

        expect($snapshot['comments']['records'])->toHaveCount(2)
            ->and($snapshot['profile']['records'])->toHaveCount(1)
            ->and($snapshot['attachments']['records'])->toHaveCount(1)
            ->and($snapshot['seoMeta']['records'])->toHaveCount(1)
            ->and($snapshot['tags']['records'])->toHaveCount(2)
            ->and($snapshot['featuredTags']['records'])->toHaveCount(2)
            ->and($post->versions()->count())->toBe(1);
    });

    it('captures the current relations whenever a version is created', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post);

        $post->update(['title' => 'Updated Title']);

        $snapshot = relationSnapshot($post);

        expect($snapshot['comments']['records'])->toHaveCount(2)
            ->and($snapshot['tags']['records'])->toHaveCount(2)
            ->and($snapshot['category']['display_only'])->toBeTrue()
            ->and($snapshot['projects']['display_only'])->toBeTrue();
    });

    it('creates a version when only relationships change', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post);

        expect($post->versions()->count())->toBe(1);

        // Relation-only change: no parent attribute is dirty
        $post->comments()->create(['author' => 'Carol', 'body' => 'Third comment']);
        $post->latestVersion()->first(); // warm nothing, keep flow realistic
        dispatchRecordSaved($post);

        expect($post->versions()->count())->toBe(2)
            ->and(relationSnapshot($post)['comments']['records'])->toHaveCount(3);
    });

    it('does not create a version when nothing changed at all', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post);

        expect($post->versions()->count())->toBe(1);

        // Simulate a Filament save with no modifications
        RelationSnapshotManager::flushRuntimeState();
        dispatchRecordSaved($post);

        expect($post->versions()->count())->toBe(1);
    });

    it('serializes pivot columns for belongsToMany and morphToMany', function () {
        $post = createVersionablePost();
        $post->tags()->attach(Tag::create(['name' => 'Solo'])->getKey(), ['position' => 7]);
        $post->featuredTags()->attach(Tag::create(['name' => 'Featured'])->getKey(), ['position' => 3]);
        dispatchRecordSaved($post);

        $snapshot = relationSnapshot($post);

        expect($snapshot['tags']['records'])->each->toHaveKey('_pivot')
            ->and($snapshot['tags']['type'])->toBe('belongsToMany')
            ->and($snapshot['featuredTags']['type'])->toBe('morphToMany');

        $pivot = reset($snapshot['tags']['records'])['_pivot'];

        expect($pivot)->toHaveKey('position')
            ->and($pivot['position'])->toBe(7)
            ->and($pivot)->not->toHaveKey('post_id')
            ->and($pivot)->not->toHaveKey('tag_id');

        $morphPivot = reset($snapshot['featuredTags']['records'])['_pivot'];

        expect($morphPivot['position'])->toBe(3)
            ->and($morphPivot)->not->toHaveKey('taggable_id')
            ->and($morphPivot)->not->toHaveKey('taggable_type');
    });

    it('excludes timestamps and hidden attributes from snapshots', function () {
        $post = createVersionablePost();
        $post->comments()->create(['author' => 'Alice', 'body' => 'Hello']);

        $post->update(['title' => 'Trigger version']);

        $record = relationSnapshot($post)['comments']['records'];

        expect($record)->not->toBeEmpty()
            ->and(reset($record))->not->toHaveKey('created_at')
            ->and(reset($record))->not->toHaveKey('updated_at')
            ->and(reset($record))->not->toHaveKey('deleted_at');
    });

    it('ignores models without the concern', function () {
        $post = Post::create([
            'title' => 'Plain Post',
            'content' => 'Plain Content',
            'user_id' => $this->user->id,
        ]);

        $post->update(['title' => 'Plain Post Updated']);

        $version = $post->latestVersion()->first();

        expect($version->getAttribute('relations'))->toBeNull();
    });
});

describe('relationship restore', function () {
    it('automatically restores hasMany children when a version is restored', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post); // v1: 2 comments

        // v2: change a comment, delete one, add one
        $post->comments()->first()->update(['body' => 'Rewritten comment']);
        $post->comments()->skip(1)->first()->delete();
        $post->comments()->create(['author' => 'Carol', 'body' => 'Third comment']);
        $post->update(['title' => 'Second version']);
        dispatchRecordSaved($post);

        expect($post->comments()->count())->toBe(2);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        $post->refresh();

        expect($post->title)->toBe('Post Title')
            ->and($post->comments()->count())->toBe(2)
            ->and($post->comments()->where('author', 'Alice')->first()->body)->toBe('First comment')
            ->and($post->comments()->where('author', 'Bob')->first()->body)->toBe('Second comment')
            ->and($post->comments()->where('author', 'Carol')->exists())->toBeFalse();
    });

    it('restores hasOne children', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post); // v1: bio = Original bio

        $post->profile()->update(['bio' => 'Changed bio']);
        $post->update(['title' => 'Second version']);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        expect($post->profile()->first()->bio)->toBe('Original bio');
    });

    it('restores morphMany and morphOne children', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post); // v1

        $post->attachments()->first()->update(['name' => 'renamed.pdf']);
        $post->attachments()->create(['name' => 'extra.txt']);
        $post->seoMeta()->update(['meta' => 'changed meta']);
        $post->update(['title' => 'Second version']);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        expect($post->attachments()->count())->toBe(1)
            ->and($post->attachments()->first()->name)->toBe('contract.pdf')
            ->and($post->seoMeta()->first()->meta)->toBe('original meta');
    });

    it('restores belongsToMany pivots including pivot columns', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post); // v1: 2 tags with positions 1,2

        $post->tags()->detach($post->tags()->first()->getKey());
        $post->tags()->attach(Tag::create(['name' => 'New tag'])->getKey(), ['position' => 9]);
        $post->update(['title' => 'Second version']);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        $tags = $post->tags()->withPivot('position')->get();

        expect($tags)->toHaveCount(2)
            ->and($tags->pluck('name')->sort()->values()->all())->toBe(['Filament', 'Laravel'])
            ->and($tags->firstWhere('name', 'Laravel')->pivot->position)->toBe(1)
            ->and($tags->firstWhere('name', 'Filament')->pivot->position)->toBe(2);
    });

    it('restores morphToMany pivots', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post); // v1: 2 featured tags

        $post->featuredTags()->sync([]);
        $post->update(['title' => 'Second version']);

        expect($post->featuredTags()->count())->toBe(0);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        expect($post->featuredTags()->count())->toBe(2)
            ->and($post->featuredTags()->first()->pivot->position)->toBeIn([1, 2]);
    });

    it('un-deletes soft-deleted children when the snapshot says they existed', function () {
        $post = createVersionablePost();
        $post->comments()->create(['author' => 'Alice', 'body' => 'Keep me']);
        dispatchRecordSaved($post); // v1: 1 comment

        $post->comments()->first()->delete(); // soft delete
        $post->update(['title' => 'Second version']);

        expect($post->comments()->count())->toBe(0);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        expect($post->comments()->count())->toBe(1)
            ->and($post->comments()->first()->body)->toBe('Keep me');
    });

    it('does not modify related records for belongsTo relations', function () {
        $category = Category::create(['name' => 'Original Category']);
        $otherCategory = Category::create(['name' => 'Other Category']);

        $post = createVersionablePost(['category_id' => $category->id]);
        dispatchRecordSaved($post); // v1: category = Original

        $post->update(['title' => 'Second version', 'category_id' => $otherCategory->id]);
        dispatchRecordSaved($post); // v2: category = Other

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        $post->refresh();

        // The foreign key is restored through the version contents...
        expect($post->category_id)->toBe($category->id)
            // ...and the related record itself is untouched.
            ->and(Category::find($otherCategory->id)->name)->toBe('Other Category')
            ->and(Category::find($category->id)->name)->toBe('Original Category');
    });

    it('skips relationship restore for versions recorded before the feature existed', function () {
        $post = createVersionablePost();

        // Simulate a pre-feature version: strip the relations payload
        $post->versions()->update(['relations' => null]);
        RelationSnapshotManager::flushRuntimeState();

        seedVersionablePostRelations($post);
        $post->update(['title' => 'Second version']);
        $post->versions()->orderBy('id')->first()->update(['relations' => null]);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        // Relations recorded after the feature keep working, but the
        // pre-feature version restored no relation data (snapshot empty).
        expect($post->comments()->count())->toBe(2);
    });

    it('does not restore display-only relations', function () {
        $post = createVersionablePost();

        // hasManyThrough(Post → User → Project) requires the user to point at the post
        $post->user->update(['versionable_post_id' => $post->getKey()]);
        Project::create(['user_id' => $post->user_id, 'name' => 'Original project']);
        $post->update(['title' => 'Trigger version']);
        dispatchRecordSaved($post);

        $post->user->projects()->first()->update(['name' => 'Renamed project']);
        $post->user->projects()->create(['name' => 'Extra project']);

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->callAction('restoreVersion')
            ->assertRedirect();

        // HasManyThrough is display-only: children are not touched.
        expect(Project::where('user_id', $post->user_id)->count())->toBe(2)
            ->and(Project::where('user_id', $post->user_id)->where('name', 'Original project')->exists())->toBeFalse();
    });
});

describe('revisions page rendering', function () {
    it('renders relation change sections on the revisions page', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post); // v1

        $post->comments()->first()->update(['body' => 'Edited body']);
        $post->update(['title' => 'Second version']);
        dispatchRecordSaved($post); // v2

        livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])
            ->assertOk()
            ->assertSee('Comments')
            ->assertSee('#1')
            ->assertSee('Edited body');
    });

    it('shows no relation sections for models without versionable relations', function () {
        $post = Post::create([
            'title' => 'Plain Post',
            'content' => 'Plain Content',
            'user_id' => $this->user->id,
        ]);

        $post->update(['title' => 'Plain Post Updated']);

        livewire(PostRevisions::class, ['record' => $post->getKey()])
            ->assertOk()
            ->assertDontSee('hasMany');
    });

    it('computes added, removed and updated record changes', function () {
        $post = createVersionablePost();
        seedVersionablePostRelations($post);
        dispatchRecordSaved($post); // v1: Alice, Bob

        $post->comments()->first()->update(['body' => 'Edited body']); // update #1
        $post->comments()->skip(1)->first()->delete(); // remove #2
        $post->comments()->create(['author' => 'Carol', 'body' => 'Third']); // add
        $post->update(['title' => 'Second version']);
        dispatchRecordSaved($post); // v2

        $page = livewire(VersionablePostRevisions::class, ['record' => $post->getKey()])->instance();
        $changeSets = collect($page->relationDiffs);
        $comments = $changeSets->firstWhere('name', 'comments');

        expect($comments)->not->toBeNull()
            ->and($comments->added)->toHaveCount(1)
            ->and($comments->removed)->toHaveCount(1)
            ->and($comments->updated)->toHaveCount(1)
            ->and($comments->added[0]->label())->toBe('#3')
            ->and($comments->added[0]->new['author'])->toBe('Carol')
            ->and($comments->removed[0]->key)->toBe(2)
            ->and($comments->updated[0]->fields)->toHaveKey('body');
    });
});
