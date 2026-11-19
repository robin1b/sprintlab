<?php

namespace App\Http\Controllers;

use App\Enums\Colleague;
use App\Models\Message;
use App\Models\Sprint;
use App\Services\Sprint\SprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(private readonly SprintService $sprints) {}

    public function __invoke(Request $request, ?Colleague $colleague = null): Response
    {
        $sprint = $this->sprints->currentFor($request->user());

        if ($sprint === null) {
            return Inertia::render('Chat', [
                'colleagues' => $this->colleagues($sprint),
                'selected' => ($colleague ?? Colleague::Sarah)->value,
                'messages' => [],
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
        ]);
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
