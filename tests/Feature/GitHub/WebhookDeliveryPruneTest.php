<?php

namespace Tests\Feature\GitHub;

use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WebhookDeliveryPruneTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(string $id, int $daysOld): WebhookDelivery
    {
        $delivery = WebhookDelivery::create(['delivery_id' => $id, 'event' => 'ping', 'payload' => []]);
        $delivery->forceFill(['created_at' => Carbon::now()->subDays($daysOld)])->saveQuietly();

        return $delivery;
    }

    public function test_old_deliveries_are_pruned_and_recent_ones_kept(): void
    {
        config(['sprintlab.retention.webhook_days' => 30]);
        $old = $this->delivery('old', 31);
        $recent = $this->delivery('recent', 29);

        $this->artisan('model:prune', ['--model' => WebhookDelivery::class])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_the_number_of_days_follows_the_config(): void
    {
        config(['sprintlab.retention.webhook_days' => 7]);
        $tenDays = $this->delivery('ten', 10);
        $threeDays = $this->delivery('three', 3);

        $this->artisan('model:prune', ['--model' => WebhookDelivery::class])->assertSuccessful();

        $this->assertModelMissing($tenDays);
        $this->assertModelExists($threeDays);
    }
}
