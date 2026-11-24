import type { TourPart } from '@/types/auth';

export type TourStep = {
    /** Value of a data-tour="..." attribute. No target: a centred card. */
    target?: string;
    title: string;
    body: string;
    /** Labelled boxes with arrows between them, left to right. */
    flow?: string[];
    /** The side of the target where the card goes first, when there is room. */
    side?: 'bottom' | 'top' | 'right' | 'left';
};

export const tours: Record<TourPart, TourStep[]> = {
    overview: [
        {
            title: 'Welcome to Workkit',
            body: 'Your setup is done. This short tour shows where everything is. It takes about a minute, and you can skip it at any time.',
        },
        {
            target: 'start-sprint',
            title: 'Start here',
            body: 'Each sprint is one working week at Workkit. Start with the demo: two short days to learn how everything works.',
        },
        {
            target: 'team',
            title: 'Your team',
            body: 'Sarah leads the team and reviews most of your code. Daniel plans the work, Josh and Emily are developers, and Claire is the customer.',
        },
        {
            target: 'nav-board',
            side: 'right',
            title: 'The board',
            body: 'Your tickets for the sprint. A ticket moves by itself when you push a branch, open a pull request and merge it.',
        },
        {
            target: 'nav-chat',
            side: 'right',
            title: 'The chat',
            body: 'Colleagues message you during the sprint. Ask them anything: they answer within a minute or two, with a hint rather than the answer.',
        },
        {
            target: 'practice-repository',
            title: 'Your practice repository',
            body: 'All your code goes here, through branches and pull requests on GitHub. Sprintlab only looks at this repository.',
        },
        {
            title: 'How a ticket flows',
            body: 'Every ticket has a key like WK-1: WK stands for Workkit, the number is the ticket. Put the key in your branch name, like feature/WK-1-short-name, and in the title of your pull request. That is how Sprintlab links your work to the board.',
            flow: [
                'Branch with the ticket key',
                'Push',
                'Pull request',
                'Review',
                'Merge',
            ],
        },
    ],
    board: [
        {
            target: 'board-columns',
            title: 'Four columns',
            body: 'To do, In progress, In review and Done. You rarely move cards yourself: your work on GitHub moves them.',
        },
        {
            target: 'guided-ticket',
            title: 'Your first ticket',
            body: 'WK-0 is a warm-up. This panel walks you through every step and ticks each one off by itself. Start here.',
        },
        {
            target: 'ticket-card',
            title: 'A ticket',
            body: 'Click a ticket to read what to build and what counts as done. It also suggests a branch name you can copy.',
        },
        {
            target: 'sprint-chip',
            title: 'Day and deadline',
            body: 'A new day starts when every ticket of the current day is done. The whole sprint has one deadline, a real week after you started.',
        },
        {
            target: 'nav-chat',
            side: 'right',
            title: 'Stuck?',
            body: 'Ask a colleague in the chat. Good questions are part of the job, and they count towards your evaluation.',
        },
        {
            title: 'When you are done',
            body: 'After the last ticket, your team lead asks you three questions about your work. Then you get your scores and feedback.',
        },
    ],
    chat: [
        {
            target: 'chat-colleagues',
            title: 'Pick a colleague',
            body: 'Each colleague knows their own part. Daniel knows the planning, Claire knows what the customer wants, and Sarah knows the code.',
        },
        {
            target: 'chat-composer',
            title: 'Write like at work',
            body: 'Short and clear works best. A reply takes a minute or two, and colleagues give hints, not the solution.',
        },
    ],
};
