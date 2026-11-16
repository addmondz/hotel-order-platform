<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class BrowserNavigationRoutesTest extends TestCase
{
    public function test_refreshing_supported_pages_serves_the_same_frontend_build(): void
    {
        $publicPath = $this->app->publicPath();
        $directory = sys_get_temp_dir().'/orderdesk-navigation-'.bin2hex(random_bytes(8));
        $index = $directory.'/dist/index.html';

        try {
            File::makeDirectory(dirname($index), 0700, true);
            File::put($index, '<!doctype html><title>OrderDesk navigation test</title>');
            $this->app->usePublicPath($directory);

            foreach ([
                '/',
                '/admin',
                '/admin/orders',
                '/admin/orders/42',
                '/admin/orders/new',
                '/admin/orders/42/edit',
                '/admin/hotels',
                '/admin/hotels/new',
                '/admin/hotels/42/edit',
                '/admin/products',
                '/admin/products/new',
                '/admin/products/42/edit',
                '/admin/prices',
                '/customer',
                '/customer/catalog',
                '/customer/orders',
                '/customer/orders/42',
                '/customer/transactions',
            ] as $path) {
                $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');

                $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse, $path);
                $this->assertSame(realpath($index), $response->baseResponse->getFile()->getRealPath(), $path);
            }

            foreach ([
                '/admin/unknown',
                '/admin/orders/abc',
                '/admin/orders/42/unknown',
                '/admin/hotels/abc/edit',
                '/admin/products/abc/edit',
                '/customer/unknown',
                '/customer/orders/new',
                '/customer/orders/42/edit',
            ] as $path) {
                $this->get($path)->assertNotFound();
            }

            $this->getJson('/api/orders/42')->assertUnauthorized();
            $this->getJson('/api/portal/orders/42')->assertUnauthorized();
            $this->getJson('/api/unknown')->assertNotFound();
        } finally {
            $this->app->usePublicPath($publicPath);
            File::deleteDirectory($directory);
        }
    }
}
