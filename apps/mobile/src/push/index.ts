import Constants from 'expo-constants';
import * as Device from 'expo-device';
import {
  AndroidImportance,
  getExpoPushTokenAsync,
  getPermissionsAsync,
  requestPermissionsAsync,
  setNotificationChannelAsync,
} from 'expo-notifications';
import { Platform } from 'react-native';

import { api } from '@/api/endpoints';
import i18n from '@/i18n';

/** The Android channel the API sends to (`channelId` in ExpoPushSender). */
export const PUSH_CHANNEL_ID = 'default';

/** The EAS project the push token belongs to: set by `eas init` in app.json. */
function easProjectId(): string | null {
  const fromConfig: unknown = Constants.expoConfig?.extra?.eas?.projectId;
  const fromBuild: unknown = Constants.easConfig?.projectId;
  const id = typeof fromConfig === 'string' && fromConfig !== '' ? fromConfig : fromBuild;
  return typeof id === 'string' && id !== '' ? id : null;
}

/**
 * Push notifications (E14) through Expo's push service, which the API calls with the
 * token registered here. Runs after each sign-in: asks for permission once, reads the Expo
 * push token and registers it with `POST /me/device-tokens`. Without permission, on a
 * simulator, on the web or before the EAS project exists it does nothing, and it never
 * throws: push is a convenience, and every message also goes by SMS.
 */
export async function registerForPush(): Promise<void> {
  if (Platform.OS === 'web' || !Device.isDevice) return;
  const projectId = easProjectId();
  if (!projectId) return;

  try {
    if (Platform.OS === 'android') {
      await setNotificationChannelAsync(PUSH_CHANNEL_ID, {
        name: i18n.t('push.channelName'),
        importance: AndroidImportance.HIGH,
      });
    }

    let { status } = await getPermissionsAsync();
    if (status !== 'granted') {
      ({ status } = await requestPermissionsAsync());
    }
    if (status !== 'granted') return;

    const { data: token } = await getExpoPushTokenAsync({ projectId });
    await api.registerDeviceToken(Platform.OS === 'ios' ? 'ios' : 'android', token);
  } catch {
    // The next sign-in tries again.
  }
}
