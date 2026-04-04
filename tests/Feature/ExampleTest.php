<?php

test('the health endpoint returns a successful response', function () {
    $response = $this->get('/up');

    $response->assertSuccessful();
});
