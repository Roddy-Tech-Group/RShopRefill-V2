<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Wallet\Events\TransactionPinCreated;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_writes_an_audit_entry(): void
    {
        $user = User::factory()->create();

        app(AuditLogService::class)->log('test_action', $user, null, null, ['key' => 'value'], $user->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test_action',
            'actor_id' => $user->id,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);
    }

    public function test_a_transaction_pin_created_event_is_audited(): void
    {
        $user = User::factory()->create();

        event(new TransactionPinCreated($user));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction_pin_created',
            'actor_id' => $user->id,
        ]);
    }

    public function test_a_successful_login_is_audited(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_login',
            'actor_id' => $user->id,
        ]);
    }

    /**
     * AuditLogListener is queued: by the time it runs, the Request facade
     * reports the WORKER, not the customer. The service must prefer the
     * request snapshot that TraceRequestMiddleware stores in hidden Context,
     * which Laravel dehydrates into the queued job.
     */
    public function test_audit_rows_record_the_original_client_ip_across_the_queue_boundary(): void
    {
        $user = User::factory()->create();

        // Simulate the hydrated queue-worker context of a request that came
        // from a real customer IP behind the proxy.
        Context::addHidden('request_ip', '203.0.113.7');
        Context::addHidden('request_user_agent', 'CustomerBrowser/1.0');

        event(new Login('web', $user, false));

        $entry = AuditLog::where('action', 'user_login')->where('actor_id', $user->id)->firstOrFail();
        $this->assertSame('203.0.113.7', $entry->ip_address);
        $this->assertSame('CustomerBrowser/1.0', $entry->user_agent);
    }

    public function test_the_trace_middleware_snapshots_the_client_ip_into_hidden_context(): void
    {
        $this->withoutVite()->get('/');

        $this->assertSame('127.0.0.1', Context::getHidden('request_ip'));
        $this->assertNotNull(Context::getHidden('request_user_agent'));
    }
}
