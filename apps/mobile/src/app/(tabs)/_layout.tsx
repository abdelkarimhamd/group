import { Tabs } from 'expo-router/js-tabs';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon, type IconName } from '@/components/Icon';
import { useFormat } from '@/i18n/useFormat';
import { colors, fonts } from '@/theme/tokens';

function tabIcon(name: IconName, focusedName: IconName) {
  function TabIcon({ focused }: { focused: boolean }) {
    return (
      <Icon
        name={focused ? focusedName : name}
        size={24}
        color={focused ? 'primary' : 'textSubtle'}
      />
    );
  }
  return TabIcon;
}

export default function TabsLayout() {
  const { t } = useFormat();
  const insets = useSafeAreaInsets();
  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.textSubtle,
        tabBarLabelStyle: { fontFamily: fonts.medium, fontSize: 11, lineHeight: 18 },
        tabBarStyle: {
          backgroundColor: colors.surface,
          borderTopColor: colors.border,
          // Icon box (28) + label line (18) + item padding (10) + bar padding (8), so the
          // Arabic font's taller line box is never clipped.
          height: 64 + insets.bottom,
          paddingTop: 4,
          paddingBottom: insets.bottom + 4,
        },
        headerTitleStyle: { fontFamily: fonts.bold, fontSize: 20 },
        headerShadowVisible: false,
        headerStyle: { backgroundColor: colors.background },
        sceneStyle: { backgroundColor: colors.background },
      }}>
      <Tabs.Screen
        name="index"
        options={{
          title: t('tabs.deals'),
          headerTitle: t('campaigns.title'),
          tabBarIcon: tabIcon('pricetags-outline', 'pricetags'),
        }}
      />
      <Tabs.Screen
        name="joins"
        options={{
          title: t('tabs.joins'),
          headerTitle: t('joins.title'),
          tabBarIcon: tabIcon('people-outline', 'people'),
        }}
      />
      <Tabs.Screen
        name="orders"
        options={{
          title: t('tabs.orders'),
          headerTitle: t('orders.title'),
          tabBarIcon: tabIcon('cube-outline', 'cube'),
        }}
      />
      <Tabs.Screen
        name="account"
        options={{
          title: t('tabs.account'),
          headerTitle: t('account.title'),
          tabBarIcon: tabIcon('person-circle-outline', 'person-circle'),
        }}
      />
    </Tabs>
  );
}
