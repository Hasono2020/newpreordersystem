<?php

test('the application returns a successful response', function () {
    // The root URL redirects unauthenticated users to login — that's correct behaviour
    $this->get('/')->assertRedirect('/login');
});
