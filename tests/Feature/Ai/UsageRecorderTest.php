<?php

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Models\AiCall;
use App\Models\User;
use App\Services\Ai\UsageRecorder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class UsageRecorderTest extends TestCase
{
    use RefreshDatabase, SprintFixtures;

    public function test_it_stores_a_priced_call(): void
    {
        $sprint = $this->startDemoSprint();

        app(UsageRecorder::class)->record(AiFeature::Chat, 'claude-sonnet-5-5-20261001', 1000, 200, $sprint->user, $sprint);

        $call = AiCall::sole();
        $this->assertSame(AiFeature::Chat, $call->feature);
        $this->assertSame('claude-sonnet-5-5-20261001', $call->model);
        $this->assertSame(1000, $call->input_tokens);
        $this->assertSame(200, $call->output_tokens);
        // 1000 x $2 + 200 x $10 per million tokens = $0.004
        $this->assertSame(4000, $call->cost_micros);
        $this->assertTrue($call->priced);
        $this->assertSame($sprint->user_id, $call->user_id);
        $this->assertSame($sprint->id, $call->sprint_id);
        $this->assertNotNull($call->created_at);
    }

    public function test_it_stores_an_unpriced_call_as_zero_dollars(): void
    {
        app(UsageRecorder::class)->record(AiFeature::Review, 'some-other-model', 1000, 200);

        $call = AiCall::sole();
        $this->assertSame(0, $call->cost_micros);
        $this->assertFalse($call->priced);
        $this->assertNull($call->user_id);
        $this->assertNull($call->sprint_id);
    }

    public function test_it_never_throws_when_recording_fails(): void
    {
        Schema::drop('ai_calls');
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        app(UsageRecorder::class)->record(AiFeature::Chat, 'claude-haiku-5-5', 10, 10, User::factory()->create());

        $this->assertCount(1, $reported);
    }
}
