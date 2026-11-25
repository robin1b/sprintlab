// Inline formatting in course text: `code`, **bold** and [label](https://url).
// Nothing else is formatting. The result is rendered as segments in a
// template, never as HTML.

export type InlineSegment =
    | { type: 'text'; text: string }
    | { type: 'code'; text: string }
    | { type: 'strong'; text: string }
    | { type: 'link'; text: string; href: string };

const PATTERN = /`([^`]+)`|\*\*(.+?)\*\*|\[([^\]]+)\]\(([^)\s]+)\)/g;

function pushText(segments: InlineSegment[], text: string): void {
    if (text === '') {
        return;
    }

    const last = segments[segments.length - 1];

    if (last?.type === 'text') {
        last.text += text;

        return;
    }

    segments.push({ type: 'text', text });
}

export function parseInline(text: string): InlineSegment[] {
    const segments: InlineSegment[] = [];
    let position = 0;

    for (const match of text.matchAll(PATTERN)) {
        const index = match.index ?? 0;

        pushText(segments, text.slice(position, index));
        position = index + match[0].length;

        if (match[1] !== undefined) {
            segments.push({ type: 'code', text: match[1] });
        } else if (match[2] !== undefined) {
            segments.push({ type: 'strong', text: match[2] });
        } else if (match[4]?.startsWith('https://')) {
            segments.push({ type: 'link', text: match[3], href: match[4] });
        } else {
            // A link that is not https stays as the text it was written as.
            pushText(segments, match[0]);
        }
    }

    pushText(segments, text.slice(position));

    return segments;
}
