<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_the_dist_frontend(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/dist');
    }
}
