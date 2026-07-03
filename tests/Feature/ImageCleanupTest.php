<?php

use App\Models\Product;
use App\Models\Trip;
use Illuminate\Support\Facades\Storage;

/*
 * Tests for orphaned product image cleanup:
 *  - images:cleanup command (delete orphans, keep referenced, --dry-run, empty dir)
 *  - ProductController::bulkDestroy deletes image files from storage
 *  - TripController::destroy deletes product images before the FK cascade
 */

// ── Local builders ───────────────────────────────────────────────────

function imgProduct($test, ?Trip $trip = null, ?string $imagePath = null): Product
{
    $trip = $trip ?? $test->openTrip();
    return Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'IMG_' . fake()->unique()->numerify('###'),
        'price'        => 100000,
        'weight_gram'  => 200,
        'status'       => 'active',
        'image'        => $imagePath,
    ]);
}

/** Put a fake JPEG on the fake public disk and return its path. */
function fakeImage(string $name): string
{
    $path = 'products/' . $name;
    Storage::disk('public')->put($path, 'fake-jpeg-bytes');
    return $path;
}

// ── images:cleanup command ───────────────────────────────────────────

test('cleanup deletes orphaned files but keeps referenced ones', function () {
    Storage::fake('public');

    $kept   = fakeImage('img_kept.jpg');
    $orphan = fakeImage('img_orphan.jpg');
    imgProduct($this, null, $kept); // only $kept is referenced in DB

    $this->artisan('images:cleanup')->assertSuccessful();

    Storage::disk('public')->assertExists($kept);
    Storage::disk('public')->assertMissing($orphan);
});

test('cleanup dry-run reports orphans but deletes nothing', function () {
    Storage::fake('public');

    $kept   = fakeImage('img_kept.jpg');
    $orphan = fakeImage('img_orphan.jpg');
    imgProduct($this, null, $kept);

    $this->artisan('images:cleanup --dry-run')->assertSuccessful();

    // Both files must still exist after a dry run
    Storage::disk('public')->assertExists($kept);
    Storage::disk('public')->assertExists($orphan);
});

test('cleanup succeeds gracefully when the products folder is empty', function () {
    Storage::fake('public');

    $this->artisan('images:cleanup')->assertSuccessful();
});

test('cleanup with no orphans deletes nothing', function () {
    Storage::fake('public');

    $a = fakeImage('img_a.jpg');
    $b = fakeImage('img_b.jpg');
    imgProduct($this, null, $a);
    imgProduct($this, null, $b);

    $this->artisan('images:cleanup')->assertSuccessful();

    Storage::disk('public')->assertExists($a);
    Storage::disk('public')->assertExists($b);
});

// ── bulkDestroy deletes image files ──────────────────────────────────

test('bulk destroy removes image files of deleted products only', function () {
    Storage::fake('public');
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $imgDelete = fakeImage('img_delete.jpg');
    $imgKeep   = fakeImage('img_keep.jpg');
    $toDelete  = imgProduct($this, $trip, $imgDelete);
    $toKeep    = imgProduct($this, $trip, $imgKeep);

    $this->actingAs($admin)
        ->post(route('products.bulk-destroy'), [
            'action'      => 'selected',
            'product_ids' => [$toDelete->id],
        ])
        ->assertRedirect();

    expect(Product::find($toDelete->id))->toBeNull();
    expect(Product::find($toKeep->id))->not->toBeNull();
    Storage::disk('public')->assertMissing($imgDelete);
    Storage::disk('public')->assertExists($imgKeep);
});

test('bulk destroy handles products without images', function () {
    Storage::fake('public');
    $admin   = $this->adminUser();
    $product = imgProduct($this); // image = null

    $this->actingAs($admin)
        ->post(route('products.bulk-destroy'), [
            'action'      => 'selected',
            'product_ids' => [$product->id],
        ])
        ->assertRedirect();

    expect(Product::find($product->id))->toBeNull();
});

// ── Trip deletion cascade deletes product images ─────────────────────

test('deleting a trip removes its product image files', function () {
    Storage::fake('public');
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $img1 = fakeImage('img_trip1.jpg');
    $img2 = fakeImage('img_trip2.jpg');
    imgProduct($this, $trip, $img1);
    imgProduct($this, $trip, $img2);

    // An unrelated product on another trip must keep its image
    $otherTrip = $this->openTrip();
    $imgOther  = fakeImage('img_other.jpg');
    imgProduct($this, $otherTrip, $imgOther);

    $this->actingAs($admin)
        ->delete(route('trips.destroy', $trip))
        ->assertRedirect(route('trips.index'));

    expect(Trip::find($trip->id))->toBeNull();
    Storage::disk('public')->assertMissing($img1);
    Storage::disk('public')->assertMissing($img2);
    Storage::disk('public')->assertExists($imgOther);
});