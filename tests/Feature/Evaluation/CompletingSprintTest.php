<?php

namespace Tests\Feature\Evaluation;

use App\Enums\TicketStatus;
use App\Jobs\PrepareSprintEvaluation;
use App\Services\Sprint\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class CompletingSprintTest extends TestCase
{
    use RefreshDatabase, SprintFixtures;

    public function test_completing_a_sprint_queues_the_evaluation_once(): void
    {
        Queue::fake();
        $sprint = $this->startDemoSprint();

        foreach (['WK-0', 'WK-1', 'WK-2', 'WK-3'] as $key) {
            app(TicketService::class)->move($this->ticket($sprint, $key), TicketStatus::Done);
        }

        $this->assertTrue($sprint->refresh()->isCompleted());
        Queue::assertPushed(PrepareSprintEvaluation::class, 1);
        Queue::assertPushed(PrepareSprintEvaluation::class, fn (PrepareSprintEvaluation $job) => $job->sprintId === $sprint->id);
    }

    public function test_nothing_is_queued_before_the_sprint_is_completed(): void
    {
        Queue::fake();
        $sprint = $this->startDemoSprint();

        app(TicketService::class)->move($this->ticket($sprint, 'WK-1'), TicketStatus::Done);

        Queue::assertNotPushed(PrepareSprintEvaluation::class);
    }
}
