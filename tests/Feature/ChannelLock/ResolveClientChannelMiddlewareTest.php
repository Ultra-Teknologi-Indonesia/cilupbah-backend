<?php

namespace Tests\Feature\ChannelLock;

use App\Enums\ClientChannelEnum;
use App\Http\Middleware\ResolveClientChannel;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ResolveClientChannelMiddlewareTest extends TestCase
{
    public function test_explicit_mobile_header_wins(): void
    {
        $mw = new ResolveClientChannel();
        $req = Request::create('/api/inbounds/1', 'POST');
        $req->headers->set('X-Client-Channel', 'MOBILE');
        $mw->handle($req, fn ($r) => new \Symfony\Component\HttpFoundation\Response('ok'));
        $this->assertSame(ClientChannelEnum::MOBILE, $req->attributes->get('client_channel'));
    }

    public function test_explicit_web_header_wins(): void
    {
        $mw = new ResolveClientChannel();
        $req = Request::create('/api/mobile/inbounds/1', 'POST');
        $req->headers->set('X-Client-Channel', 'WEB');
        $mw->handle($req, fn ($r) => new \Symfony\Component\HttpFoundation\Response('ok'));
        $this->assertSame(ClientChannelEnum::WEB, $req->attributes->get('client_channel'));
    }

    public function test_infer_from_mobile_route_when_header_missing(): void
    {
        $mw = new ResolveClientChannel();
        $req = Request::create('/api/mobile/inbounds/1', 'POST');
        $mw->handle($req, fn ($r) => new \Symfony\Component\HttpFoundation\Response('ok'));
        $this->assertSame(ClientChannelEnum::MOBILE, $req->attributes->get('client_channel'));
    }

    public function test_default_web_when_no_signal(): void
    {
        $mw = new ResolveClientChannel();
        $req = Request::create('/api/inbounds/1', 'POST');
        $mw->handle($req, fn ($r) => new \Symfony\Component\HttpFoundation\Response('ok'));
        $this->assertSame(ClientChannelEnum::WEB, $req->attributes->get('client_channel'));
    }
}
