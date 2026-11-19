<?php

namespace Tests\Feature\Sprint;

use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\ScenarioEvent;
use App\Services\Sprint\TicketService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class TickCommandTest extends TestCase
{
    use RefreshDatabase, SprintFixtures;

    public function test_the_tick_command_fires_due_events_and_reports_the_count(): void
    {
        $sprint = $this->startDemoSprint();
        ScenarioEvent::where('key', 'change-wk-2')->sole()->update(['delay_minutes' => 5]);
        app(TicketService::class)->move($this->ticket($sprint, 'WK-2'), TicketStatus::InReview);

        $this->artisan('sprintlab:tick')->expectsOutput('Fired 0 scenario events.')->assertSuccessful();

        $this->travel(6)->minutes();

        $this->artisan('sprintlab:tick')->expectsOutput('Fired 1 scenario events.')->assertSuccessful();
        $this->assertSame(1, Message::where('body', 'like', 'Sorry%')->count());
    }

    public function test_the_tick_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'sprintlab:tick'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
