<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A client-side JS error used to be genuinely invisible once shipped —
 * only ever seen in a cashier's own devtools, so a "কিছু একটা ভুল হয়েছে"
 * report had no trail to follow. This endpoint writes the real error into
 * the normal Laravel log instead, tagged with who/where hit it.
 */
class ClientErrorLogTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_logged_in_user_can_report_a_client_error(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Log::shouldReceive('warning')->once()->with(
            '[client-js-error] SyntaxError: Unexpected token < in JSON',
            \Mockery::on(fn ($context) => $context['shop_id'] === $shop->id
                && $context['user_id'] === $owner->id
                && $context['context'] === 'openGatewaySheet'),
        );

        $response = $this->actingAs($owner, 'web')->postJson('/app/client-error-log', [
            'message' => 'SyntaxError: Unexpected token < in JSON',
            'stack' => 'at fetch...',
            'url' => 'https://pos.zaylotix.com/app/more',
            'context' => 'openGatewaySheet',
        ]);

        $response->assertOk();
    }

    public function test_message_is_required(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->postJson('/app/client-error-log', [])
            ->assertJsonValidationErrors('message');
    }

    public function test_a_logged_out_visitor_cannot_reach_it(): void
    {
        $this->postJson('/app/client-error-log', ['message' => 'x'])->assertStatus(401);
    }
}
