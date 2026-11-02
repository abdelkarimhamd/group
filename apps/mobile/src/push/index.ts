/**
 * Push notifications (E14). Firebase Cloud Messaging is a third-party integration and lands
 * last, behind this function: until then it does nothing, so no fake token ever reaches
 * `/me/device-tokens` and the API never tries to push to it.
 *
 * The real version asks for permission with expo-notifications, reads the native FCM/APNs
 * token with getDevicePushTokenAsync() and calls api.registerDeviceToken(platform, token).
 */
export async function registerForPush(): Promise<void> {
  // Intentionally empty until the Firebase integration.
}
