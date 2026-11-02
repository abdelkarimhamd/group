import { Alert, Platform } from 'react-native';

/** A yes/no question: the native alert on devices, `window.confirm` in the browser preview. */
export function confirmAction(options: {
  title: string;
  message: string;
  confirmText: string;
  cancelText: string;
  destructive?: boolean;
}): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(globalThis.confirm?.(`${options.title}\n\n${options.message}`) ?? false);
  }
  return new Promise((resolve) => {
    Alert.alert(
      options.title,
      options.message,
      [
        { text: options.cancelText, style: 'cancel', onPress: () => resolve(false) },
        {
          text: options.confirmText,
          style: options.destructive ? 'destructive' : 'default',
          onPress: () => resolve(true),
        },
      ],
      { cancelable: true, onDismiss: () => resolve(false) },
    );
  });
}
