import { Platform } from 'react-native';

import { api } from '@/api/endpoints';

import { PUSH_CHANNEL_ID, registerForPush } from '..';

const constants: { expoConfig: { extra?: { eas?: { projectId?: string } } } | null } = {
  expoConfig: { extra: { eas: { projectId: 'eas-project-1' } } },
};
const device = { __esModule: true, isDevice: true };

jest.mock('expo-constants', () => ({ __esModule: true, default: constants }));
jest.mock('expo-device', () => device);
jest.mock('expo-notifications', () => ({
  AndroidImportance: { HIGH: 4 },
  setNotificationChannelAsync: jest.fn(async () => null),
  getPermissionsAsync: jest.fn(async () => ({ status: 'undetermined' })),
  requestPermissionsAsync: jest.fn(async () => ({ status: 'granted' })),
  getExpoPushTokenAsync: jest.fn(async () => ({ type: 'expo', data: 'ExponentPushToken[abc]' })),
}));
jest.mock('@/api/endpoints', () => ({ api: { registerDeviceToken: jest.fn(async () => {}) } }));

// eslint-disable-next-line @typescript-eslint/no-require-imports
const Notifications = require('expo-notifications') as {
  setNotificationChannelAsync: jest.Mock;
  getPermissionsAsync: jest.Mock;
  requestPermissionsAsync: jest.Mock;
  getExpoPushTokenAsync: jest.Mock;
};
const register = api.registerDeviceToken as jest.Mock;

function setPlatform(os: 'ios' | 'android' | 'web') {
  Object.defineProperty(Platform, 'OS', { configurable: true, get: () => os });
}

beforeEach(() => {
  jest.clearAllMocks();
  setPlatform('ios');
  device.isDevice = true;
  constants.expoConfig = { extra: { eas: { projectId: 'eas-project-1' } } };
});

describe('registerForPush', () => {
  it('asks once, then registers the Expo push token for the EAS project', async () => {
    await registerForPush();

    expect(Notifications.requestPermissionsAsync).toHaveBeenCalledTimes(1);
    expect(Notifications.getExpoPushTokenAsync).toHaveBeenCalledWith({
      projectId: 'eas-project-1',
    });
    expect(register).toHaveBeenCalledWith('ios', 'ExponentPushToken[abc]');
    expect(Notifications.setNotificationChannelAsync).not.toHaveBeenCalled();
  });

  it('creates the channel the API sends to on Android', async () => {
    setPlatform('android');
    Notifications.getPermissionsAsync.mockResolvedValueOnce({ status: 'granted' });

    await registerForPush();

    expect(Notifications.setNotificationChannelAsync).toHaveBeenCalledWith(
      PUSH_CHANNEL_ID,
      expect.objectContaining({ name: 'Order and deal updates' }),
    );
    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();
    expect(register).toHaveBeenCalledWith('android', 'ExponentPushToken[abc]');
  });

  it('registers nothing when the customer says no', async () => {
    Notifications.requestPermissionsAsync.mockResolvedValueOnce({ status: 'denied' });

    await registerForPush();

    expect(Notifications.getExpoPushTokenAsync).not.toHaveBeenCalled();
    expect(register).not.toHaveBeenCalled();
  });

  it('does nothing on a simulator, on the web or before the EAS project exists', async () => {
    device.isDevice = false;
    await registerForPush();

    device.isDevice = true;
    setPlatform('web');
    await registerForPush();

    setPlatform('ios');
    constants.expoConfig = { extra: {} };
    await registerForPush();

    expect(Notifications.getPermissionsAsync).not.toHaveBeenCalled();
    expect(register).not.toHaveBeenCalled();
  });

  it('never throws when the token or the API fails', async () => {
    Notifications.getExpoPushTokenAsync.mockRejectedValueOnce(new Error('no network'));
    await expect(registerForPush()).resolves.toBeUndefined();

    register.mockRejectedValueOnce(new Error('500'));
    await expect(registerForPush()).resolves.toBeUndefined();
  });
});
