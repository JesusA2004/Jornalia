<script setup lang="ts">
import { computed } from 'vue';

/**
 * Vista previa de Markdown básica y segura (sin v-html): encabezados,
 * listas, citas, párrafos, **negritas** y *cursivas*.
 */
const props = defineProps<{ source: string }>();

type Inline = { text: string; bold?: boolean; italic?: boolean };
type Block =
    | { type: 'h'; level: 1 | 2 | 3; content: Inline[] }
    | { type: 'p'; content: Inline[] }
    | { type: 'quote'; content: Inline[] }
    | { type: 'ul' | 'ol'; items: Inline[][] }
    | { type: 'hr' };

function inline(text: string): Inline[] {
    const parts: Inline[] = [];
    const pattern = /(\*\*[^*]+\*\*|\*[^*]+\*)/g;
    let last = 0;

    for (const match of text.matchAll(pattern)) {
        const index = match.index ?? 0;

        if (index > last) {
            parts.push({ text: text.slice(last, index) });
        }

        const token = match[0];
        parts.push(
            token.startsWith('**')
                ? { text: token.slice(2, -2), bold: true }
                : { text: token.slice(1, -1), italic: true },
        );
        last = index + token.length;
    }

    if (last < text.length) {
        parts.push({ text: text.slice(last) });
    }

    return parts;
}

const blocks = computed<Block[]>(() => {
    const out: Block[] = [];
    let paragraph: string[] = [];

    const flush = () => {
        if (paragraph.length) {
            out.push({ type: 'p', content: inline(paragraph.join(' ')) });
            paragraph = [];
        }
    };

    for (const raw of props.source.split(/\r?\n/)) {
        const line = raw.trim();
        let m: RegExpMatchArray | null;

        if (line === '') {
            flush();
        } else if ((m = line.match(/^(#{1,3})\s+(.*)$/))) {
            flush();
            out.push({
                type: 'h',
                level: m[1].length as 1 | 2 | 3,
                content: inline(m[2]),
            });
        } else if (/^(-{3,}|\*{3,})$/.test(line)) {
            flush();
            out.push({ type: 'hr' });
        } else if (
            (m = line.match(/^[-*]\s+(.*)$/)) ||
            (m = line.match(/^\d+[.)]\s+(.*)$/))
        ) {
            flush();
            const kind = /^\d/.test(line) ? 'ol' : 'ul';
            const prev = out[out.length - 1];

            if (prev && prev.type === kind) {
                prev.items.push(inline(m[1]));
            } else {
                out.push({ type: kind, items: [inline(m[1])] });
            }
        } else if ((m = line.match(/^>\s?(.*)$/))) {
            flush();
            out.push({ type: 'quote', content: inline(m[1]) });
        } else {
            paragraph.push(line);
        }
    }

    flush();

    return out;
});
</script>

<template>
    <div class="max-w-none space-y-3 text-sm leading-relaxed text-foreground">
        <p v-if="blocks.length === 0" class="text-muted-foreground">
            Sin contenido.
        </p>
        <template v-for="(block, i) in blocks" :key="i">
            <component
                :is="`h${block.level + 1}`"
                v-if="block.type === 'h'"
                :class="[
                    'font-display font-bold',
                    block.level === 1
                        ? 'pt-2 text-xl'
                        : block.level === 2
                          ? 'pt-1 text-lg'
                          : 'text-base',
                ]"
            >
                <template v-for="(part, j) in block.content" :key="j"
                    ><strong v-if="part.bold">{{ part.text }}</strong
                    ><em v-else-if="part.italic">{{ part.text }}</em
                    ><template v-else>{{ part.text }}</template></template
                >
            </component>
            <p v-else-if="block.type === 'p'">
                <template v-for="(part, j) in block.content" :key="j"
                    ><strong v-if="part.bold">{{ part.text }}</strong
                    ><em v-else-if="part.italic">{{ part.text }}</em
                    ><template v-else>{{ part.text }}</template></template
                >
            </p>
            <blockquote
                v-else-if="block.type === 'quote'"
                class="border-l-2 border-brand pl-3 text-muted-foreground"
            >
                <template v-for="(part, j) in block.content" :key="j"
                    ><strong v-if="part.bold">{{ part.text }}</strong
                    ><em v-else-if="part.italic">{{ part.text }}</em
                    ><template v-else>{{ part.text }}</template></template
                >
            </blockquote>
            <hr v-else-if="block.type === 'hr'" class="border-border" />
            <component
                :is="block.type"
                v-else
                :class="[
                    'space-y-1 pl-5',
                    block.type === 'ul' ? 'list-disc' : 'list-decimal',
                ]"
            >
                <li v-for="(item, k) in block.items" :key="k">
                    <template v-for="(part, j) in item" :key="j"
                        ><strong v-if="part.bold">{{ part.text }}</strong
                        ><em v-else-if="part.italic">{{ part.text }}</em
                        ><template v-else>{{ part.text }}</template></template
                    >
                </li>
            </component>
        </template>
    </div>
</template>
