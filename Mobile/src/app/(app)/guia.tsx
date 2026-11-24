import { View } from 'react-native';

import { HelpSteps } from '@/components/help';
import { Mascot } from '@/components/ui/brand';
import { Screen } from '@/components/ui/Screen';
import { AppText, Card } from '@/components/ui/primitives';
import { HELP } from '@/features/help/content';
import { spacing } from '@/theme/tokens';

const ORDER = ['inicio', 'ubicacion', 'firma', 'convenio', 'nip', 'avisos', 'historial', 'solicitudes', 'sincronizacion', 'perfil'] as const;

export default function GuideScreen() {
  return (
    <Screen edges={['bottom']}>
      <View style={{ alignItems: 'center' }}>
        <Mascot pose="presenta" height={130} />
      </View>
      {ORDER.map((k) => (
        <Card key={k} style={{ gap: spacing.md }}>
          <AppText variant="heading">{HELP[k].title}</AppText>
          <HelpSteps topic={HELP[k]} />
        </Card>
      ))}
    </Screen>
  );
}
