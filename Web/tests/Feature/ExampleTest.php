<?php

test('la raíz lleva al panel y, sin sesión, al inicio de sesión', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('login'));
});
