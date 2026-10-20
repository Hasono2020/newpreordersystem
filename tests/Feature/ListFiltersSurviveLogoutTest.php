<?php

/*
 * Reported: a user picked the "Korea October" trip on the Orders list, logged out,
 * logged back in, and the list was back on "All trips". The remembered filters lived
 * only in the session, and logging out empties the session. They are now also saved
 * on the user and put back into the session at login.
 */

use App\Models\User;

function lfLogin($test, User $user): void
{
    $test->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
}

test('the trip filter on Orders is still selected after logging out and in again', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->get(route('orders.index', ['trip_id' => $trip->id]))->assertOk();
    $this->post(route('logout'));
    $this->assertGuest();

    lfLogin($this, $admin);
    $this->get(route('dashboard'))->assertOk()->assertSee('trip_id=' . $trip->id, false);
});

test('every list keeps its own filter across logout', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin);
    $this->get(route('orders.index', ['trip_id' => $trip->id, 'payment_status' => 'unpaid']))->assertOk();
    $this->get(route('purchasing.index', ['trip_id' => $trip->id]))->assertOk();
    $this->post(route('logout'));

    lfLogin($this, $admin);
    $html = $this->get(route('dashboard'))->assertOk()->getContent();
    expect($html)->toContain('trip_id=' . $trip->id . '&amp;payment_status=unpaid');
    expect($html)->toContain(route('purchasing.index', ['trip_id' => $trip->id], false));
});

test('the page number is not kept, only the filters', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->get(route('orders.index', ['trip_id' => $trip->id, 'page' => 3]))->assertOk();
    expect($admin->fresh()->list_urls['orders'])->toBe('/orders?trip_id=' . $trip->id);

    $this->post(route('logout'));
    lfLogin($this, $admin);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('page=3', false);
});

test('clearing the filter is remembered too', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin);
    $this->get(route('orders.index', ['trip_id' => $trip->id]));
    $this->get(route('orders.index'));
    $this->post(route('logout'));

    lfLogin($this, $admin);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('trip_id=' . $trip->id, false);
});

test('each person has their own saved filters', function () {
    $admin = $this->adminUser();
    $other = $this->staffUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->get(route('orders.index', ['trip_id' => $trip->id]));
    $this->post(route('logout'));

    lfLogin($this, $other);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('trip_id=' . $trip->id, false);
});

test('only changed filters are written to the database', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->get(route('orders.index', ['trip_id' => $trip->id]));
    $before = \DB::table('users')->where('id', $admin->id)->value('list_urls');
    $this->get(route('orders.index', ['trip_id' => $trip->id, 'page' => 2]));

    expect(\DB::table('users')->where('id', $admin->id)->value('list_urls'))->toBe($before);
});

test('a saved address that is not a path on this site is ignored at login', function () {
    $admin = $this->adminUser();
    \DB::table('users')->where('id', $admin->id)->update(['list_urls' => json_encode([
        'orders'    => 'https://evil.example/orders',
        'purchasing'=> '//evil.example/x',
        'unknown'   => '/orders',
    ])]);

    lfLogin($this, $admin);
    $html = $this->get(route('dashboard'))->assertOk()->getContent();
    expect($html)->not->toContain('evil.example');
});
