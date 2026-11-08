import { useQueryClient } from '@tanstack/react-query';
import { router, type Href } from 'expo-router';
import {
  addNotificationReceivedListener,
  addNotificationResponseReceivedListener,
  addPushTokenListener,
  clearLastNotificationResponse,
  DEFAULT_ACTION_IDENTIFIER,
  getLastNotificationResponse,
  type NotificationResponse,
} from 'expo-notifications';
import { useEffect, useRef } from 'react';
import { Platform } from 'react-native';

import { queryKeys } from '@/api/queries';
import { useAuth } from '@/auth/session';
import { authToken } from '@/auth/token';

import { isPrivateTarget, pushLinks, pushTarget, registerForPush } from '.';

/**
 * What the app does with push messages while it runs:
 * - a message that arrives refreshes the joins, orders and deal it is about, so an open
 *   screen shows the news at once;
 * - tapping a message (also the one that launched the app) opens its order, join or deal,
 *   through sign-in when the customer has signed out since;
 * - when the system hands the app a new push token, it is registered again.
 *
 * Renders nothing. Mount once, inside the router, the query client and the session.
 */
export function PushObserver() {
  const queryClient = useQueryClient();
  const { isSignedIn } = useAuth();
  const signedIn = useRef(isSignedIn);
  useEffect(() => {
    signedIn.current = isSignedIn;
  }, [isSignedIn]);

  useEffect(() => {
    if (Platform.OS === 'web') return;

    const open = (response: NotificationResponse) => {
      if (response.actionIdentifier !== DEFAULT_ACTION_IDENTIFIER) return;
      const target = pushTarget(response.notification.request.content.data);
      if (!target) return;
      if (isPrivateTarget(target) && !signedIn.current) {
        router.push({ pathname: '/sign-in', params: { next: nextPath(target) } });
      } else {
        router.push(target);
      }
      // Handled: a reload (the language switch restarts the app) must not open it again.
      try {
        clearLastNotificationResponse();
      } catch {
        // Not available on this platform.
      }
    };

    // Without the native module (tests, the browser preview) the listeners may come back empty.
    const subscriptions: ({ remove?: () => void } | undefined)[] = [];
    try {
      subscriptions.push(
        addNotificationReceivedListener((notification) => {
          const links = pushLinks(notification.request.content.data);
          void queryClient.invalidateQueries({ queryKey: queryKeys.participations });
          void queryClient.invalidateQueries({ queryKey: queryKeys.orders });
          if (links.campaignId) {
            void queryClient.invalidateQueries({ queryKey: queryKeys.campaign(links.campaignId) });
          }
        }),
        addNotificationResponseReceivedListener(open),
        addPushTokenListener(() => {
          if (authToken.get()) void registerForPush({ prompt: false });
        }),
      );
      const launchedBy = getLastNotificationResponse();
      if (launchedBy) open(launchedBy);
    } catch (error) {
      // Push is not available here (the browser preview, or a test).
      if (__DEV__) console.warn('Push listeners unavailable', error);
    }

    return () => subscriptions.forEach((subscription) => subscription?.remove?.());
  }, [queryClient]);

  return null;
}

/** The in-app path of a target, for sign-in's `next`. */
function nextPath(target: Href): string {
  if (typeof target === 'string') return target;
  const id = String(target.params?.id ?? '');
  return target.pathname.replace('[id]', encodeURIComponent(id));
}
