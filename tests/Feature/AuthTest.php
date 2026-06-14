<?php
use App\Models\User;

test('login page is accessible', function () {
    $this->get('/login')->assertStatus(200);
});

test('admin can login', function () {
    $admin = User::factory()->admin()->create(['email' => 'admin@test.com', 'password' => bcrypt('password')]);
    $this->post('/login', ['email' => 'admin@test.com', 'password' => 'password'])
         ->assertRedirect('/');
    $this->assertAuthenticatedAs($admin);
});

test('inactive user cannot login', function () {
    User::factory()->inactive()->create(['email' => 'old@test.com', 'password' => bcrypt('password')]);
    $this->post('/login', ['email' => 'old@test.com', 'password' => 'password'])
         ->assertSessionHasErrors();
    $this->assertGuest();
});

test('wrong password is rejected', function () {
    User::factory()->admin()->create(['email' => 'admin@test.com', 'password' => bcrypt('password')]);
    $this->post('/login', ['email' => 'admin@test.com', 'password' => 'wrong'])
         ->assertSessionHasErrors();
    $this->assertGuest();
});

test('unauthenticated user is redirected from protected pages', function () {
    $this->get('/orders')->assertRedirect('/login');
    $this->get('/customers')->assertRedirect('/login');
    $this->get('/payments')->assertRedirect('/login');
});
