<?php

/*
 * Reported: clicking "Orders" in the sidebar always reset to the unfiltered
 * list, even when the user had a specific trip selected — because the
 * sidebar link used a plain route() call instead of the RememberListUrl
 * helper already used elsewhere (e.g. the "Back" link on order show/edit).
 * Fixed by wiring the same helper into the sidebar links for orders,
 * payments, products, and customers.
 */

test('the Orders sidebar link returns to the last filtered trip instead of resetting', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    // Visiting the filtered list is what RememberListUrl stores in session.
    $this->actingAs($admin)->get(route('orders.index', ['trip_id' => $trip->id]));

    // A totally different page should still show the sidebar pointing back
    // at that filtered view, not a bare /orders link.
    $response = $this->actingAs($admin)->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('trip_id=' . $trip->id, false);
});

test('the Orders sidebar link falls back to the plain list when nothing is remembered yet', function () {
    $admin = $this->adminUser();

    $response = $this->actingAs($admin)->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee(route('orders.index'), false);
});
