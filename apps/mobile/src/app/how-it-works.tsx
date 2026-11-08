import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Screen } from '@/components/Screen';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { colors, space } from '@/theme/tokens';

const STEPS = [1, 2, 3, 4] as const;
const QUESTIONS = [1, 2, 3, 4, 5] as const;

/** The website's "How it works" page: the four steps and the common questions. */
export default function HowItWorksScreen() {
  const { t } = useFormat();
  return (
    <Screen footer={<Button title={t('help.browse')} onPress={() => router.navigate('/')} />}>
      <View style={styles.gap}>
        <Text variant="title">{t('help.title')}</Text>
        <Text color="textMuted">{t('help.intro')}</Text>
      </View>

      {STEPS.map((n) => (
        <Card key={n} style={styles.step}>
          <View style={styles.number}>
            <Text weight="bold" color="onPrimary">
              {String(n)}
            </Text>
          </View>
          <View style={styles.flex}>
            <Text weight="semibold">{t(`help.step${n}Title`)}</Text>
            <Text variant="small" color="textMuted">
              {t(`help.step${n}Body`)}
            </Text>
          </View>
        </Card>
      ))}

      <Text variant="heading">{t('help.faqTitle')}</Text>
      <Card style={styles.faq}>
        {QUESTIONS.map((n) => (
          <View key={n} style={styles.gap}>
            <Text weight="semibold">{t(`help.faq${n}Q`)}</Text>
            <Text variant="small" color="textMuted">
              {t(`help.faq${n}A`)}
            </Text>
          </View>
        ))}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
    gap: space.xs,
  },
  gap: {
    gap: space.xs,
  },
  step: {
    flexDirection: 'row',
    gap: space.md,
    alignItems: 'flex-start',
  },
  number: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  faq: {
    gap: space.lg,
  },
});
