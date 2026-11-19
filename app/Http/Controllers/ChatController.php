<?php

namespace App\Http\Controllers;

use App\Enums\Colleague;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Models\Message;
use App\Models\Sprint;
use App\Services\Chat\ChatService;
use App\Services\Sprint\SprintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly SprintService $sprints,
        private readonly ChatService $chat,
    ) {}

    public function __invoke(Request $request, ?Colleague $colleague = null): Response
    {
        $sprint = $this->sprints->currentFor($request->user());

        if ($sprint === null) {
            return Inertia::render('Chat', [
                'colleagues' => $this->colleagues($sprint),
                'selected' => ($colleague ?? Colleague::Sarah)->value,
                'messages' => [],
                'can_send' => false,
                'send_blocked_reason' => $this->blockedReason(null),
                'awaiting_reply' => false,
                'max_characters' => (int) config('sprintlab.ai.chat_max_characters'),
            ]);
        }

        $colleague ??= $this->newestConversation($sprint);

        // Opening a conversation counts as reading it.
        $sprint->messages()
            ->where('colleague', $colleague->value)
            ->where('from_user', false)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        $messages = $sprint->messages()
            ->where('colleague', $colleague->value)
            ->with('ticket:id,key')
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'from_user' => $message->from_user,
                'created_at' => $message->created_at?->toIso8601String(),
                'ticket_key' => $message->ticket?->key,
            ])->all();

        return Inertia::render('Chat', [
            'colleagues' => $this->colleagues($sprint),
            'selected' => $colleague->value,
            'messages' => $messages,
            'can_send' => $this->blockedReason($sprint) === null,
            'send_blocked_reason' => $this->blockedReason($sprint),
            'awaiting_reply' => $this->awaitingReply($sprint, $colleague),
            'max_characters' => (int) config('sprintlab.ai.chat_max_characters'),
        ]);
    }

    public function store(StoreChatMessageRequest $request, Colleague $colleague): RedirectResponse
    {
        $sprint = $this->sprints->currentFor($request->user());

        // The flash messages are the same texts the page shows instead of the box.
        if ($sprint === null || ! $sprint->isActive()) {
            return back()->with('error', $sprint === null ? 'Start a sprint to chat with your team.' : 'Your sprint is paused. Resume it to chat.');
        }

        $this->chat->send($sprint, $colleague, $request->validated('body'));

        return back();
    }

    /**
     * Why the message box is not shown, or null when the user can write.
     */
    private function blockedReason(?Sprint $sprint): ?string
    {
        return match (true) {
            $sprint === null => 'Start a sprint to chat with your team.',
            $sprint->isPaused() => 'Your sprint is paused. Resume it to chat.',
            ! config('sprintlab.ai.enabled') => 'Replies from your colleagues are switched off right now.',
            default => null,
        };
    }

    /**
     * True while a message of the user waits for its reply. After ten minutes
     * we stop showing "typing": the job has failed or was never run.
     */
    private function awaitingReply(Sprint $sprint, Colleague $colleague): bool
    {
        return $sprint->isActive()
            && $sprint->messages()
                ->where('colleague', $colleague->value)
                ->where('from_user', true)
                ->whereNull('answered_at')
                ->where('created_at', '>', Carbon::now()->subMinutes(10))
                ->exists();
    }

    private function newestConversation(Sprint $sprint): Colleague
    {
        $newest = $sprint->messages()->orderByDesc('id')->first();

        return $newest === null ? Colleague::Sarah : $newest->colleague;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function colleagues(?Sprint $sprint): array
    {
        $unread = collect();
        $last = collect();

        if ($sprint !== null) {
            $unread = $sprint->messages()
                ->where('from_user', false)->whereNull('read_at')
                ->selectRaw('colleague, count(*) as total')->groupBy('colleague')
                ->pluck('total', 'colleague');

            $lastIds = $sprint->messages()->selectRaw('max(id) as id')->groupBy('colleague')->pluck('id');
            $last = Message::query()->whereIn('id', $lastIds)->get()->keyBy(fn (Message $m) => $m->colleague->value);
        }

        return collect(Colleague::cases())->map(fn (Colleague $colleague) => [
            'key' => $colleague->value,
            'name' => $colleague->displayName(),
            'role' => $colleague->role(),
            'unread' => (int) ($unread[$colleague->value] ?? 0),
            'last_message' => ($last[$colleague->value] ?? null) === null
                ? null
                : Str::limit($last[$colleague->value]->body, 60),
        ])->values()->all();
    }
}
