<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RadarControllerTest extends WebTestCase
{
    public function testIndexPageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('canvas#radarCanvas');
    }

    public function testApiEndpointReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/planes?lat=43.4170&lon=24.6067');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
