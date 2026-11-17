import { Fragment } from 'react';
import { View } from 'react-native';

import { spacing } from '@/theme/tokens';

import { AppText } from './ui/primitives';

/**
 * Renderizador Markdown mínimo y seguro (sólo texto): encabezados, párrafos,
 * listas y **negritas**. No interpreta HTML ni enlaces ejecutables.
 */
export function Markdown({ source }: { source: string }) {
  const blocks = source.replace(/\r\n/g, '\n').split(/\n{2,}/);
  return (
    <View style={{ gap: spacing.md }}>
      {blocks.map((block, i) => {
        const trimmed = block.trim();
        if (!trimmed) return null;
        const heading = /^(#{1,6})\s+(.*)$/.exec(trimmed);
        if (heading) {
          return (
            <AppText key={i} variant={heading[1]!.length <= 2 ? 'heading' : 'bodyStrong'}>
              {inline(heading[2]!)}
            </AppText>
          );
        }
        const lines = trimmed.split('\n');
        if (lines.every((l) => /^\s*([-*]|\d+\.)\s+/.test(l))) {
          return (
            <View key={i} style={{ gap: 4 }}>
              {lines.map((l, j) => {
                const ordered = /^\s*(\d+)\.\s+/.exec(l);
                return (
                  <View key={j} style={{ flexDirection: 'row', gap: spacing.sm }}>
                    <AppText variant="body" color="textMuted">
                      {ordered ? `${ordered[1]}.` : '•'}
                    </AppText>
                    <AppText variant="body" style={{ flex: 1 }}>
                      {inline(l.replace(/^\s*([-*]|\d+\.)\s+/, ''))}
                    </AppText>
                  </View>
                );
              })}
            </View>
          );
        }
        return (
          <AppText key={i} variant="body">
            {inline(lines.join(' '))}
          </AppText>
        );
      })}
    </View>
  );
}

function inline(text: string) {
  const parts = text.split(/(\*\*[^*]+\*\*)/g);
  return parts.map((p, i) =>
    p.startsWith('**') && p.endsWith('**') ? (
      <AppText key={i} variant="bodyStrong">
        {p.slice(2, -2)}
      </AppText>
    ) : (
      <Fragment key={i}>{p}</Fragment>
    ),
  );
}
