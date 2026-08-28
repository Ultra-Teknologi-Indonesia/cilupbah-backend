<?php

namespace Tests\Unit;

use App\Enums\ClientChannelEnum;
use App\Models\User;
use App\Traits\AutoScopeMobileToAuth;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AutoScopeMobileToAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        auth()->forgetUser();
        Mockery::close();

        parent::tearDown();
    }

    public function test_web_request_keeps_picker_filter_selected_by_user(): void
    {
        $this->authenticateNonOwner('web-user');
        $request = $this->request(ClientChannelEnum::WEB, [
            'filter' => ['picker_id' => 'selected-picker'],
        ]);

        $this->probe()->applyScope($request, 'picker_id');

        $this->assertSame('selected-picker', data_get($request->query('filter', []), 'picker_id'));
    }

    public function test_web_request_does_not_add_implicit_picker_filter(): void
    {
        $this->authenticateNonOwner('web-user');
        $request = $this->request(ClientChannelEnum::WEB);

        $this->probe()->applyScope($request, 'picker_id');

        $this->assertNull(data_get($request->query('filter', []), 'picker_id'));
    }

    public function test_mobile_request_is_scoped_to_authenticated_non_owner(): void
    {
        $this->authenticateNonOwner('mobile-picker');
        $request = $this->request(ClientChannelEnum::MOBILE, [
            'filter' => ['picker_id' => 'different-picker'],
        ]);

        $this->probe()->applyScope($request, 'picker_id');

        $this->assertSame('mobile-picker', data_get($request->query('filter', []), 'picker_id'));
    }

    public function test_mobile_override_does_not_leak_into_web_requests(): void
    {
        $this->authenticateNonOwner('mobile-picker');

        $this->assertSame(
            'selected-picker',
            $this->probe()->override($this->request(ClientChannelEnum::WEB), 'selected-picker'),
        );
        $this->assertSame(
            'mobile-picker',
            $this->probe()->override($this->request(ClientChannelEnum::MOBILE), 'selected-picker'),
        );
    }

    private function authenticateNonOwner(string $id): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => $id]);
        $user->shouldReceive('hasRole')->with('owner')->andReturnFalse();

        auth()->setUser($user);
    }

    private function request(ClientChannelEnum $channel, array $query = []): Request
    {
        $request = Request::create('/api/v1/outbound/picklists', 'GET', $query);
        $request->attributes->set('client_channel', $channel);

        return $request;
    }

    private function probe(): object
    {
        return new class
        {
            use AutoScopeMobileToAuth;

            public function applyScope(Request $request, string $filterKey): void
            {
                $this->forceMobileScopeToAuth($request, $filterKey);
            }

            public function override(Request $request, ?string $webValue): ?string
            {
                return $this->overrideForMobile($request, $webValue);
            }
        };
    }
}
