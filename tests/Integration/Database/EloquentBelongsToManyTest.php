<?php

namespace Illuminate\Tests\Integration\Database\EloquentBelongsToManyTest;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Tests\Integration\Database\DatabaseTestCase;

/**
 * @group integration
 */
class EloquentBelongsToManyTest extends DatabaseTestCase
{
    public function setUp()
    {
        parent::setUp();

        Schema::create('posts', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('tags', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts_tags', function ($table) {
            $table->integer('post_id');
            $table->integer('tag_id');
            $table->string('flag')->default('');
            $table->timestamps();
        });

        Schema::create('items', function ($table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('labels', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('items_labels', function ($table) {
            $table->string('item_uuid');
            $table->integer('label_id');
            $table->string('flag')->default('');
            $table->timestamps();
        });

        Carbon::setTestNow(null);
    }

    /**
     * @test
     */
    public function basic_create_and_retrieve()
    {
        Carbon::setTestNow(
            Carbon::createFromFormat('Y-m-d H:i:s', '2017-10-10 10:10:10')
        );

        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);
        $tag2 = Tag::create(['name' => str_random()]);
        $tag3 = Tag::create(['name' => str_random()]);

        $post->tags()->sync([
            $tag->id => ['flag' => 'taylor'],
            $tag2->id => ['flag' => ''],
            $tag3->id => ['flag' => 'exclude'],
        ]);

        // Tags with flag = exclude should be excluded
        $this->assertCount(2, $post->tags);
        $this->assertInstanceOf(Collection::class, $post->tags);
        $this->assertEquals($tag->name, $post->tags[0]->name);
        $this->assertEquals($tag2->name, $post->tags[1]->name);

        // Testing on the pivot model
        $this->assertInstanceOf(Pivot::class, $post->tags[0]->pivot);
        $this->assertEquals($post->id, $post->tags[0]->pivot->post_id);
        $this->assertEquals('post_id', $post->tags[0]->pivot->getForeignKey());
        $this->assertEquals('tag_id', $post->tags[0]->pivot->getOtherKey());
        $this->assertEquals('posts_tags', $post->tags[0]->pivot->getTable());
        $this->assertEquals(
            [
                'post_id' => '1', 'tag_id' => '1', 'flag' => 'taylor',
                'created_at' => '2017-10-10 10:10:10', 'updated_at' => '2017-10-10 10:10:10',
            ],
            $post->tags[0]->pivot->toArray()
        );
    }

    /**
     * @test
     */
    public function basic_create_and_retrieve_with_ci_string_foreign_keys()
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() != 'sqlite') {
            $this->markTestSkipped('DB needs to be configured with sqlite');
        }

        Schema::dropIfExists('items');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('items_labels');

        \DB::statement('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT unique NOT NULL COLLATE NOCASE,
            title TEXT,
            created_at DATETIME,
            updated_at DATETIME
        )');

        \DB::statement('CREATE TABLE labels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            created_at DATETIME,
            updated_at DATETIME
        )');

        \DB::statement('CREATE TABLE items_labels (
            item_uuid TEXT NOT NULL COLLATE NOCASE,
            label_id INTEGER NOT NULL,
            flag TEXT DEFAULT \'\',
            created_at DATETIME,
            updated_at DATETIME
        )');

        Carbon::setTestNow(
            Carbon::createFromFormat('Y-m-d H:i:s', '2019-05-31 10:10:10')
        );

        $item = Item::create(['uuid' => 'abc', 'title' => str_random()]);

        $label = Label::create(['name' => str_random()]);
        $label2 = Label::create(['name' => str_random()]);
        $label3 = Label::create(['name' => str_random()]);

        $item->labels()->sync([
            $label->id => ['flag' => 'tisho'],
            $label2->id => ['flag' => ''],
            $label3->id => ['flag' => 'exclude'],
        ]);

        // Tags with flag = exclude should be excluded
        $this->assertCount(2, $item->labels);
        $this->assertInstanceOf(Collection::class, $item->labels);
        $this->assertEquals($label->name, $item->labels[0]->name);
        $this->assertEquals($label2->name, $item->labels[1]->name);

        // Testing on the pivot model
        $this->assertInstanceOf(Pivot::class, $item->labels[0]->pivot);
        $this->assertEquals($item->uuid, $item->labels[0]->pivot->item_uuid);
        $this->assertEquals('item_uuid', $item->labels[0]->pivot->getForeignKey());
        $this->assertEquals('label_id', $item->labels[0]->pivot->getOtherKey());
        $this->assertEquals('items_labels', $item->labels[0]->pivot->getTable());
        $this->assertEquals(
            [
                'item_uuid' => 'abc', 'label_id' => '1', 'flag' => 'tisho',
                'created_at' => '2019-05-31 10:10:10', 'updated_at' => '2019-05-31 10:10:10',
            ],
            $item->labels[0]->pivot->toArray()
        );

        // Change the uuid to uppercase
        $item->uuid = 'ABC';
        $item->save();

        $item = Item::with('labels')->find($item->id)->first();

        $this->assertCount(2, $item->labels);
        $this->assertInstanceOf(Collection::class, $item->labels);
        $this->assertEquals($label->name, $item->labels[0]->name);
        $this->assertEquals($label2->name, $item->labels[1]->name);

        // Testing on the pivot model
        $this->assertInstanceOf(Pivot::class, $item->labels[0]->pivot);
        // assert that the keys are kept as they are in the DB (ABC vs abc)
        $this->assertNotEquals($item->uuid, $item->labels[0]->pivot->item_uuid);
        $this->assertEquals(strtolower($item->uuid), strtolower($item->labels[0]->pivot->item_uuid));
        $this->assertEquals('item_uuid', $item->labels[0]->pivot->getForeignKey());
        $this->assertEquals('label_id', $item->labels[0]->pivot->getOtherKey());
        $this->assertEquals('items_labels', $item->labels[0]->pivot->getTable());
        $this->assertEquals(
            [
                'item_uuid' => 'abc', 'label_id' => '1', 'flag' => 'tisho',
                'created_at' => '2019-05-31 10:10:10', 'updated_at' => '2019-05-31 10:10:10',
            ],
            $item->labels[0]->pivot->toArray()
        );
    }

    /**
     * @test
     */
    public function refresh_on_other_model_works()
    {
        $post = Post::create(['title' => str_random()]);
        $tag = Tag::create(['name' => $tagName = str_random()]);

        $post->tags()->sync([
            $tag->id,
        ]);

        $post->load('tags');

        $loadedTag = $post->tags()->first();

        $tag->update(['name' => 'newName']);

        $this->assertEquals($tagName, $loadedTag->name);

        $this->assertEquals($tagName, $post->tags[0]->name);

        $loadedTag->refresh();

        $this->assertEquals('newName', $loadedTag->name);

        $post->refresh();

        $this->assertEquals('newName', $post->tags[0]->name);
    }

    /**
     * @test
     */
    public function custom_pivot_class()
    {
        Carbon::setTestNow(
            Carbon::createFromFormat('Y-m-d H:i:s', '2017-10-10 10:10:10')
        );

        $post = Post::create(['title' => str_random()]);

        $tag = TagWithCustomPivot::create(['name' => str_random()]);

        $post->tagsWithCustomPivot()->attach($tag->id);

        $this->assertInstanceOf(CustomPivot::class, $post->tagsWithCustomPivot[0]->pivot);

        $this->assertEquals([
            'post_id' => '1',
            'tag_id' => '1',
        ], $post->tagsWithCustomAccessor[0]->tag->toArray());

        $pivot = $post->tagsWithCustomPivot[0]->pivot;
        $pivot->tag_id = 2;
        $pivot->save();

        $this->assertEquals(1, CustomPivot::count());
        $this->assertEquals(1, CustomPivot::first()->post_id);
        $this->assertEquals(2, CustomPivot::first()->tag_id);
    }

    /**
     * @test
     */
    public function attach_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);
        $tag2 = Tag::create(['name' => str_random()]);
        $tag3 = Tag::create(['name' => str_random()]);
        $tag4 = Tag::create(['name' => str_random()]);
        $tag5 = Tag::create(['name' => str_random()]);
        $tag6 = Tag::create(['name' => str_random()]);
        $tag7 = Tag::create(['name' => str_random()]);
        $tag8 = Tag::create(['name' => str_random()]);

        $post->tags()->attach($tag->id);
        $this->assertEquals($tag->name, $post->tags[0]->name);
        $this->assertNotNull($post->tags[0]->pivot->created_at);

        $post->tags()->attach($tag2->id, ['flag' => 'taylor']);
        $post->load('tags');
        $this->assertEquals($tag2->name, $post->tags[1]->name);
        $this->assertEquals('taylor', $post->tags[1]->pivot->flag);

        $post->tags()->attach([$tag3->id, $tag4->id]);
        $post->load('tags');
        $this->assertEquals($tag3->name, $post->tags[2]->name);
        $this->assertEquals($tag4->name, $post->tags[3]->name);

        $post->tags()->attach([$tag5->id => ['flag' => 'mohamed'], $tag6->id => ['flag' => 'adam']]);
        $post->load('tags');
        $this->assertEquals($tag5->name, $post->tags[4]->name);
        $this->assertEquals('mohamed', $post->tags[4]->pivot->flag);
        $this->assertEquals($tag6->name, $post->tags[5]->name);
        $this->assertEquals('adam', $post->tags[5]->pivot->flag);

        $post->tags()->attach(new Collection([$tag7, $tag8]));
        $post->load('tags');
        $this->assertEquals($tag7->name, $post->tags[6]->name);
        $this->assertEquals($tag8->name, $post->tags[7]->name);
    }

    /**
     * @test
     */
    public function detach_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);
        $tag2 = Tag::create(['name' => str_random()]);
        $tag3 = Tag::create(['name' => str_random()]);
        $tag4 = Tag::create(['name' => str_random()]);
        $tag5 = Tag::create(['name' => str_random()]);
        $tag6 = Tag::create(['name' => str_random()]);
        $tag7 = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $this->assertEquals(Tag::pluck('name'), $post->tags->pluck('name'));

        $post->tags()->detach($tag->id);
        $post->load('tags');
        $this->assertEquals(
            Tag::whereNotIn('id', [$tag->id])->pluck('name'),
            $post->tags->pluck('name')
        );

        $post->tags()->detach([$tag2->id, $tag3->id]);
        $post->load('tags');
        $this->assertEquals(
            Tag::whereNotIn('id', [$tag->id, $tag2->id, $tag3->id])->pluck('name'),
            $post->tags->pluck('name')
        );

        $post->tags()->detach(new Collection([$tag4, $tag5]));
        $post->load('tags');
        $this->assertEquals(
            Tag::whereNotIn('id', [$tag->id, $tag2->id, $tag3->id, $tag4->id, $tag5->id])->pluck('name'),
            $post->tags->pluck('name')
        );

        $this->assertCount(2, $post->tags);
        $post->tags()->detach();
        $post->load('tags');
        $this->assertCount(0, $post->tags);
    }

    /**
     * @test
     */
    public function first_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $this->assertEquals($tag->name, $post->tags()->first()->name);
    }

    /**
     * @test
     * @expectedException \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function firstOrFail_method()
    {
        $post = Post::create(['title' => str_random()]);

        $post->tags()->firstOrFail(['id' => 10]);
    }

    /**
     * @test
     */
    public function find_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);
        $tag2 = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $this->assertEquals($tag2->name, $post->tags()->find($tag2->id)->name);
        $this->assertCount(2, $post->tags()->findMany([$tag->id, $tag2->id]));
    }

    /**
     * @test
     * @expectedException \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $post->tags()->findOrFail(10);
    }

    /**
     * @test
     */
    public function findOrNew_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $this->assertEquals($tag->id, $post->tags()->findOrNew($tag->id)->id);

        $this->assertNull($post->tags()->findOrNew('asd')->id);
        $this->assertInstanceOf(Tag::class, $post->tags()->findOrNew('asd'));
    }

    /**
     * @test
     */
    public function firstOrNew_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $this->assertEquals($tag->id, $post->tags()->firstOrNew(['id' => $tag->id])->id);

        $this->assertNull($post->tags()->firstOrNew(['id' => 'asd'])->id);
        $this->assertInstanceOf(Tag::class, $post->tags()->firstOrNew(['id' => 'asd']));
    }

    /**
     * @test
     */
    public function firstOrCreate_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $this->assertEquals($tag->id, $post->tags()->firstOrCreate(['name' => $tag->name])->id);

        $new = $post->tags()->firstOrCreate(['name' => 'wavez']);
        $this->assertEquals('wavez', $new->name);
        $this->assertNotNull($new->id);
    }

    /**
     * @test
     */
    public function updateOrCreate_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);

        $post->tags()->attach(Tag::all());

        $post->tags()->updateOrCreate(['id' => $tag->id], ['name' => 'wavez']);
        $this->assertEquals('wavez', $tag->fresh()->name);

        $post->tags()->updateOrCreate(['id' => 'asd'], ['name' => 'dives']);
        $this->assertNotNull($post->tags()->whereName('dives')->first());
    }

    /**
     * @test
     */
    public function sync_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);
        $tag2 = Tag::create(['name' => str_random()]);
        $tag3 = Tag::create(['name' => str_random()]);
        $tag4 = Tag::create(['name' => str_random()]);

        $post->tags()->sync([$tag->id, $tag2->id]);

        $this->assertEquals(
            Tag::whereIn('id', [$tag->id, $tag2->id])->pluck('name'),
            $post->load('tags')->tags->pluck('name')
        );

        $output = $post->tags()->sync([$tag->id, $tag3->id, $tag4->id]);

        $this->assertEquals(
            Tag::whereIn('id', [$tag->id, $tag3->id, $tag4->id])->pluck('name'),
            $post->load('tags')->tags->pluck('name')
        );

        $this->assertEquals([
            'attached' => [$tag3->id, $tag4->id],
            'detached' => [1 => $tag2->id],
            'updated' => [],
        ], $output);

        $post->tags()->sync([]);
        $this->assertEmpty($post->load('tags')->tags);

        $post->tags()->sync([
            $tag->id => ['flag' => 'taylor'],
            $tag2->id => ['flag' => 'mohamed'],
        ]);
        $post->load('tags');
        $this->assertEquals($tag->name, $post->tags[0]->name);
        $this->assertEquals('taylor', $post->tags[0]->pivot->flag);
        $this->assertEquals($tag2->name, $post->tags[1]->name);
        $this->assertEquals('mohamed', $post->tags[1]->pivot->flag);
    }

    /**
     * @test
     */
    public function syncWithoutDetaching_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);
        $tag2 = Tag::create(['name' => str_random()]);

        $post->tags()->sync([$tag->id]);

        $this->assertEquals(
            Tag::whereIn('id', [$tag->id])->pluck('name'),
            $post->load('tags')->tags->pluck('name')
        );

        $post->tags()->syncWithoutDetaching([$tag2->id]);

        $this->assertEquals(
            Tag::whereIn('id', [$tag->id, $tag2->id])->pluck('name'),
            $post->load('tags')->tags->pluck('name')
        );
    }

    /**
     * @test
     */
    public function toggle_method()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = Tag::create(['name' => str_random()]);
        $tag2 = Tag::create(['name' => str_random()]);

        $post->tags()->toggle([$tag->id]);

        $this->assertEquals(
            Tag::whereIn('id', [$tag->id])->pluck('name'),
            $post->load('tags')->tags->pluck('name')
        );

        $post->tags()->toggle([$tag2->id, $tag->id]);

        $this->assertEquals(
            Tag::whereIn('id', [$tag2->id])->pluck('name'),
            $post->load('tags')->tags->pluck('name')
        );

        $post->tags()->toggle([$tag2->id, $tag->id => ['flag' => 'taylor']]);
        $post->load('tags');
        $this->assertEquals(
            Tag::whereIn('id', [$tag->id])->pluck('name'),
            $post->tags->pluck('name')
        );
        $this->assertEquals('taylor', $post->tags[0]->pivot->flag);
    }

    /**
     * @test
     */
    public function touching_parent()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = TouchingTag::create(['name' => str_random()]);

        $post->touchingTags()->attach([$tag->id]);

        $this->assertNotEquals('2017-10-10 10:10:10', $post->fresh()->updated_at->toDateTimeString());

        Carbon::setTestNow(
            Carbon::createFromFormat('Y-m-d H:i:s', '2017-10-10 10:10:10')
        );

        $tag->update(['name' => $tag->name]);
        $this->assertNotEquals('2017-10-10 10:10:10', $post->fresh()->updated_at->toDateTimeString());

        $tag->update(['name' => str_random()]);
        $this->assertEquals('2017-10-10 10:10:10', $post->fresh()->updated_at->toDateTimeString());
    }

    /**
     * @test
     */
    public function touching_related_models_on_sync()
    {
        $tag = TouchingTag::create(['name' => str_random()]);

        $post = Post::create(['title' => str_random()]);

        $this->assertNotEquals('2017-10-10 10:10:10', $post->fresh()->updated_at->toDateTimeString());
        $this->assertNotEquals('2017-10-10 10:10:10', $tag->fresh()->updated_at->toDateTimeString());

        Carbon::setTestNow(
            Carbon::createFromFormat('Y-m-d H:i:s', '2017-10-10 10:10:10')
        );

        $tag->posts()->sync([$post->id]);

        $this->assertEquals('2017-10-10 10:10:10', $post->fresh()->updated_at->toDateTimeString());
        $this->assertEquals('2017-10-10 10:10:10', $tag->fresh()->updated_at->toDateTimeString());
    }

    /**
     * @test
     */
    public function no_touching_happens_if_not_configured()
    {
        $tag = Tag::create(['name' => str_random()]);

        $post = Post::create(['title' => str_random()]);

        $this->assertNotEquals('2017-10-10 10:10:10', $post->fresh()->updated_at->toDateTimeString());
        $this->assertNotEquals('2017-10-10 10:10:10', $tag->fresh()->updated_at->toDateTimeString());

        Carbon::setTestNow(
            Carbon::createFromFormat('Y-m-d H:i:s', '2017-10-10 10:10:10')
        );

        $tag->posts()->sync([$post->id]);

        $this->assertNotEquals('2017-10-10 10:10:10', $post->fresh()->updated_at->toDateTimeString());
        $this->assertNotEquals('2017-10-10 10:10:10', $tag->fresh()->updated_at->toDateTimeString());
    }

    /**
     * @test
     */
    public function can_retrieve_related_ids()
    {
        $post = Post::create(['title' => str_random()]);

        DB::table('tags')->insert([
            ['id' => 200, 'name' => 'excluded'],
            ['id' => 300, 'name' => str_random()],
        ]);

        DB::table('posts_tags')->insert([
            ['post_id' => $post->id, 'tag_id' => 200, 'flag' => ''],
            ['post_id' => $post->id, 'tag_id' => 300, 'flag' => 'exclude'],
            ['post_id' => $post->id, 'tag_id' => 400, 'flag' => ''],
        ]);

        $this->assertEquals([200, 400], $post->tags()->allRelatedIds()->toArray());
    }

    /**
     * @test
     */
    public function can_touch_related_models()
    {
        $post = Post::create(['title' => str_random()]);

        DB::table('tags')->insert([
            ['id' => 200, 'name' => str_random()],
            ['id' => 300, 'name' => str_random()],
        ]);

        DB::table('posts_tags')->insert([
            ['post_id' => $post->id, 'tag_id' => 200, 'flag' => ''],
            ['post_id' => $post->id, 'tag_id' => 300, 'flag' => 'exclude'],
            ['post_id' => $post->id, 'tag_id' => 400, 'flag' => ''],
        ]);

        Carbon::setTestNow(
            Carbon::createFromFormat('Y-m-d H:i:s', '2017-10-10 10:10:10')
        );

        $post->tags()->touch();

        foreach ($post->tags()->pluck('tags.updated_at') as $date) {
            $this->assertEquals('2017-10-10 10:10:10', $date);
        }

        $this->assertNotEquals('2017-10-10 10:10:10', Tag::find(300)->updated_at);
    }

    public function test_custom_related_key()
    {
        $post = Post::create(['title' => str_random()]);

        $tag = $post->tagsWithCustomRelatedKey()->create(['name' => str_random()]);
        $this->assertEquals($tag->name, $post->tagsWithCustomRelatedKey()->first()->pivot->tag_id);

        $post->tagsWithCustomRelatedKey()->detach($tag);

        $post->tagsWithCustomRelatedKey()->attach($tag);
        $this->assertEquals($tag->name, $post->tagsWithCustomRelatedKey()->first()->pivot->tag_id);

        $post->tagsWithCustomRelatedKey()->detach(new Collection([$tag]));

        $post->tagsWithCustomRelatedKey()->attach(new Collection([$tag]));
        $this->assertEquals($tag->name, $post->tagsWithCustomRelatedKey()->first()->pivot->tag_id);
    }
}

class Post extends Model
{
    public $table = 'posts';
    public $timestamps = true;
    protected $guarded = ['id'];
    protected $touches = ['touchingTags'];

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'posts_tags', 'post_id', 'tag_id')
            ->withPivot('flag')
            ->withTimestamps()
            ->wherePivot('flag', '<>', 'exclude');
    }

    public function touchingTags()
    {
        return $this->belongsToMany(TouchingTag::class, 'posts_tags', 'post_id', 'tag_id')
            ->withTimestamps();
    }

    public function tagsWithCustomPivot()
    {
        return $this->belongsToMany(TagWithCustomPivot::class, 'posts_tags', 'post_id', 'tag_id')
            ->using(CustomPivot::class)
            ->withTimestamps();
    }

    public function tagsWithCustomAccessor()
    {
        return $this->belongsToMany(TagWithCustomPivot::class, 'posts_tags', 'post_id', 'tag_id')
            ->using(CustomPivot::class)
            ->as('tag');
    }

    public function tagsWithCustomRelatedKey()
    {
        return $this->belongsToMany(Tag::class, 'posts_tags', 'post_id', 'tag_id', 'id', 'name')
            ->withPivot('flag');
    }
}

class Item extends Model
{
    public $table = 'items';
    public $timestamps = true;
    protected $guarded = ['id'];

    public function labels()
    {
        return $this->belongsToMany(Label::class, 'items_labels', 'item_uuid', 'label_id', 'uuid', 'id')
            ->withPivot('flag')
            ->withTimestamps()
            ->wherePivot('flag', '<>', 'exclude');
    }
}

class Label extends Model
{
    public $table = 'labels';
    public $timestamps = true;
    protected $guarded = ['id'];

    public function items()
    {
        return $this->belongsToMany(Item::class, 'items_labels', 'label_id', 'item_uuid');
    }
}

class Tag extends Model
{
    public $table = 'tags';
    public $timestamps = true;
    protected $guarded = ['id'];

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'posts_tags', 'tag_id', 'post_id');
    }
}

class TouchingTag extends Model
{
    public $table = 'tags';
    public $timestamps = true;
    protected $guarded = ['id'];
    protected $touches = ['posts'];

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'posts_tags', 'tag_id', 'post_id');
    }
}

class TagWithCustomPivot extends Model
{
    public $table = 'tags';
    public $timestamps = true;
    protected $guarded = ['id'];

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'posts_tags', 'tag_id', 'post_id');
    }
}

class CustomPivot extends Pivot
{
    protected $table = 'posts_tags';
}
