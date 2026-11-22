<?php

namespace Tests\Feature\Chat;

use App\Ai\Agents\ColleagueChat;
use App\Enums\Colleague;
use App\Models\Sprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class ColleagueChatGuidanceTest extends TestCase
{
    use RefreshDatabase;
    use SprintFixtures;

    private function instructions(Sprint $sprint, Colleague $colleague): string
    {
        return ColleagueChat::make(sprint: $sprint, colleague: $colleague)->instructions();
    }

    public function test_the_briefing_and_only_the_notes_of_this_colleague_are_in_the_instructions(): void
    {
        $sprint = $this->startDemoSprint();
        $sprint->scenario->update([
            'briefing' => 'BRIEFING: the app is a monolith.',
            'colleague_notes' => ['emily' => 'EMILY-NOTE: look at the observers.', 'claire' => 'CLAIRE-NOTE: weekly export.'],
        ]);
        $sprint = $sprint->fresh();

        $emily = $this->instructions($sprint, Colleague::Emily);

        $this->assertStringContainsString("give one pointer at a time, never recite it.\nBRIEFING: the app is a monolith.\n\n", $emily);
        $this->assertStringContainsString("Things only you know. Share a detail when Robin asks for it, the way a colleague answers a question, not all at once.\nEMILY-NOTE: look at the observers.\n\nSprint: ", $emily);
        $this->assertStringNotContainsString('CLAIRE-NOTE', $emily);

        $claire = $this->instructions($sprint, Colleague::Claire);
        $this->assertStringContainsString('CLAIRE-NOTE: weekly export.', $claire);
        $this->assertStringNotContainsString('EMILY-NOTE', $claire);

        // Colleagues without notes still get the briefing, but no heading for notes.
        $josh = $this->instructions($sprint, Colleague::Josh);
        $this->assertStringContainsString('BRIEFING: the app is a monolith.', $josh);
        $this->assertStringNotContainsString('Things only you know', $josh);
    }

    public function test_without_briefing_and_notes_there_are_no_extra_headings(): void
    {
        $sprint = $this->startDemoSprint();

        $text = $this->instructions($sprint, Colleague::Emily);

        $this->assertStringNotContainsString('What you know about the codebase', $text);
        $this->assertStringNotContainsString('Things only you know', $text);
        $this->assertStringContainsString("review criteria.\n\nSprint: ", $text);
    }
}
