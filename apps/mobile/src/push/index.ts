import Constants from 'expo-constants';
import * as Device from 'expo-device';
import type { Href } from 'expo-router';
import {
  AndroidImportance,
  getExpoPushTokenAsync,
  getPermissionsAsync,
  requestPermissionsAsync,
  setNotificationChannelAsync,
  setNotificationHandler,
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
 * token registered here. Runs after each sign-in (`prompt: true`, the default, asks for
 * permission the first time) and at launch while signed in (`prompt: false`, which only
 * refreshes a token the customer already allowed). Reads the Expo push token and registers it
 * with `POST /me/device-tokens`. Without permission, on a simulator, on the web or before the
 * EAS project exists it does nothing, and it never throws: push is a convenience, and every
 * message also goes by SMS.
 */
export async function registerForPush({ prompt = true }: { prompt?: boolean } = {}): Promise<void> {
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
    if (status !== 'granted' && prompt) {
      ({ status } = await requestPermissionsAsync());
    }
    if (status !== 'granted') return;

    const { data: token } = await getExpoPushTokenAsync({ projectId });
    await api.registerDeviceToken(Platform.OS === 'ios' ? 'ios' : 'android', token);
  } catch (error) {
    // The next launch or sign-in tries again. On Android a missing Firebase config
    // (google-services.json, see app.config.ts) ends up here.
    if (__DEV__) console.warn('Push registration failed', error);
  }
}

let configured = false;

/**
 * Shows messages that arrive while the app is open as a banner, like the system does when it
 * is closed. Call once at startup.
 */
export function configurePush(): void {
  if (configured || Platform.OS === 'web') return;
  configured = true;
  try {
    setNotificationHandler({
      handleNotification: async () => ({
        shouldShowBanner: true,
        shouldShowList: true,
        shouldPlaySound: true,
        shouldSetBadge: false,
      }),
    });
  } catch (error) {
    if (__DEV__) console.warn('Push handler unavailable', error);
  }
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** The IDs the API puts in a push message's data (PushLinks on the API). */
export type PushLinks = { orderId?: string; participationId?: string; campaignId?: string };

/** Reads the IDs from a message's data, ignoring anything that is not a UUID. */
export function pushLinks(data: unknown): PushLinks {
  if (!data || typeof data !== 'object') return {};
  const record = data as Record<string, unknown>;
  const id = (key: string): string | undefined => {
    const value = record[key];
    return typeof value === 'string' && UUID.test(value) ? value : undefined;
  };
  return {
    orderId: id('order_id'),
    participationId: id('participation_id'),
    campaignId: id('campaign_id'),
  };
}

/**
 * The screen a tap on a message opens: the order, else the join, else the deal. Null when
 * the message names none of them.
 */
export function pushTarget(data: unknown): Href | null {
  const links = pushLinks(data);
  if (links.orderId) return { pathname: '/orders/[id]', params: { id: links.orderId } };
  if (links.participationId) {
    return { pathname: '/joins/[id]', params: { id: links.participationId } };
  }
  if (links.campaignId) return { pathname: '/campaign/[id]', params: { id: links.campaignId } };
  return null;
}

/** Whether the target needs the customer signed in (their joins and orders do). */
export function isPrivateTarget(href: Href): boolean {
  return typeof href === 'object' && href.pathname !== '/campaign/[id]';
}
