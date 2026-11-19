<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Http\Requests\Sprint\UpdateTicketRequest;
use App\Models\Ticket;
use App\Services\Sprint\TicketService;
use DomainException;
use Illuminate\Http\RedirectResponse;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        try {
            $this->tickets->move($ticket, TicketStatus::from($request->string('status')->toString()));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }
}
