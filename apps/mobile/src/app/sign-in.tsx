import { useMutation } from '@tanstack/react-query';
import * as Device from 'expo-device';
import { router, useLocalSearchParams, type Href } from 'expo-router';
import { useState } from 'react';
import { Platform, StyleSheet, View } from 'react-native';

import { api } from '@/api/endpoints';
import { isApiError } from '@/api/errors';
import type { OtpChallenge } from '@/api/types';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Notice } from '@/components/Notice';
import { Screen } from '@/components/Screen';
import { Text } from '@/components/Text';
import { TextField } from '@/components/TextField';
import { useNow } from '@/components/useNow';
import { clock, useFormat } from '@/i18n/useFormat';
import { digitsOnly, formatSaudiMobile, normalizeSaudiMobile } from '@/lib/phone';
import { safeNextPath } from '@/lib/navigation';
import { remainingUntil } from '@/lib/time';
import { space } from '@/theme/tokens';

const CODE_LENGTH = 6;

function deviceName(): string {
  const model = Device.modelName ?? Platform.OS;
  const os = [Device.osName, Device.osVersion].filter(Boolean).join(' ');
  return (os ? `${model} · ${os}` : model).slice(0, 100);
}

export default function SignInScreen() {
  const { next } = useLocalSearchParams<{ next?: string }>();
  const [challenge, setChallenge] = useState<{ phone: string; data: OtpChallenge } | null>(null);

  return challenge ? (
    <CodeStep
      phone={challenge.phone}
      challenge={challenge.data}
      onNewChallenge={(data) => setChallenge({ phone: challenge.phone, data })}
      onChangeNumber={() => setChallenge(null)}
      next={safeNextPath(next)}
    />
  ) : (
    <PhoneStep onSent={(phone, data) => setChallenge({ phone, data })} />
  );
}

function rateLimitMessage(error: unknown, t: ReturnType<typeof useFormat>['t']): string | null {
  if (isApiError(error) && error.status === 429) {
    const seconds = error.retryAfterSeconds ?? 60;
    return t('errors.rateLimited', { time: clock(remainingUntil(Date.now() + seconds * 1000)) });
  }
  return null;
}

function PhoneStep({ onSent }: { onSent: (phone: string, challenge: OtpChallenge) => void }) {
  const { t } = useFormat();
  const [input, setInput] = useState('');
  const [touched, setTouched] = useState(false);
  const phone = normalizeSaudiMobile(input);

  const send = useMutation({
    mutationFn: (value: string) => api.requestOtp(value),
    onSuccess: (data, value) => onSent(value, data),
  });

  const submit = () => {
    setTouched(true);
    if (phone) send.mutate(phone);
  };

  const error = send.error
    ? (rateLimitMessage(send.error, t) ??
      (isApiError(send.error)
        ? (send.error.fieldError('phone') ?? send.error.message)
        : t('errors.generic')))
    : touched && !phone
      ? t('validation.phone')
      : undefined;

  return (
    <Screen
      edges={['bottom']}
      footer={
        <Button
          testID="send-code"
          title={t('auth.sendCode')}
          onPress={submit}
          loading={send.isPending}
        />
      }>
      <View style={styles.intro}>
        <Text variant="title">{t('auth.signInTitle')}</Text>
        <Text color="textMuted">{t('auth.signInBody')}</Text>
      </View>
      <TextField
        testID="phone-input"
        label={t('auth.phoneLabel')}
        placeholder={t('auth.phonePlaceholder')}
        value={input}
        onChangeText={(text) => {
          setInput(text);
          send.reset();
        }}
        keyboardType="phone-pad"
        textContentType="telephoneNumber"
        autoComplete="tel"
        autoFocus
        ltr
        maxLength={16}
        onSubmitEditing={submit}
        error={error}
      />
    </Screen>
  );
}

function CodeStep({
  phone,
  challenge,
  onNewChallenge,
  onChangeNumber,
  next,
}: {
  phone: string;
  challenge: OtpChallenge;
  onNewChallenge: (challenge: OtpChallenge) => void;
  onChangeNumber: () => void;
  next: Href | null;
}) {
  const { t } = useFormat();
  const { signIn } = useAuth();
  const now = useNow(1_000);
  const [code, setCode] = useState('');
  const [resent, setResent] = useState(false);
  const resendWait = remainingUntil(challenge.resend_available_at, now);

  const verify = useMutation({
    mutationFn: (value: string) =>
      api.verifyOtp({
        challenge_id: challenge.challenge_id,
        code: value,
        device_name: deviceName(),
      }),
    onSuccess: (session) => {
      signIn(session);
      if (session.is_new_user) {
        router.replace({
          pathname: '/profile',
          params: { welcome: '1', next: next ? String(next) : undefined },
        });
      } else if (next) {
        router.replace(next);
      } else if (router.canGoBack()) {
        router.back();
      } else {
        router.replace('/');
      }
    },
  });

  const resend = useMutation({
    mutationFn: () => api.requestOtp(phone),
    onSuccess: (data) => {
      setCode('');
      verify.reset();
      setResent(true);
      onNewChallenge(data);
    },
  });

  const submit = (value: string) => {
    if (value.length === CODE_LENGTH) verify.mutate(value);
  };

  const verifyError = verify.error;
  const codeSpent =
    isApiError(verifyError) &&
    (verifyError.code === 'otp_expired' || verifyError.code === 'otp_attempts_exhausted');
  const message = (() => {
    if (!verifyError) return undefined;
    const limited = rateLimitMessage(verifyError, t);
    if (limited) return limited;
    if (!isApiError(verifyError)) return t('errors.generic');
    switch (verifyError.code) {
      case 'otp_invalid':
        return t('auth.codeInvalid');
      case 'otp_expired':
        return t('auth.codeExpired');
      case 'otp_attempts_exhausted':
        return t('auth.codeExhausted');
      default:
        return verifyError.fieldError('code') ?? verifyError.message;
    }
  })();

  return (
    <Screen
      edges={['bottom']}
      footer={
        <>
          <Button
            testID="verify-code"
            title={t('auth.verify')}
            onPress={() => submit(code)}
            loading={verify.isPending}
            disabled={code.length !== CODE_LENGTH || codeSpent}
          />
          <Button
            testID="resend-code"
            variant="ghost"
            title={
              resendWait.totalMs > 0
                ? t('auth.resendIn', { time: clock(resendWait) })
                : t('auth.resend')
            }
            disabled={resendWait.totalMs > 0}
            loading={resend.isPending}
            onPress={() => resend.mutate()}
          />
        </>
      }>
      <View style={styles.intro}>
        <Text variant="title">{t('auth.verifyTitle')}</Text>
        <Text color="textMuted">{t('auth.verifyBody', { phone: formatSaudiMobile(phone) })}</Text>
        <Button
          variant="ghost"
          compact
          title={t('auth.changeNumber')}
          onPress={onChangeNumber}
          style={styles.start}
        />
      </View>
      {resent && !verifyError ? <Notice tone="success" message={t('auth.codeResent')} /> : null}
      {resend.error ? (
        <Notice tone="danger" message={rateLimitMessage(resend.error, t) ?? t('errors.generic')} />
      ) : null}
      <TextField
        testID="code-input"
        label={t('auth.codeLabel')}
        value={code}
        onChangeText={(text) => {
          const digits = digitsOnly(text, CODE_LENGTH);
          setCode(digits);
          if (verify.isError) verify.reset();
          submit(digits);
        }}
        keyboardType="number-pad"
        textContentType="oneTimeCode"
        autoComplete="sms-otp"
        autoFocus
        ltr
        maxLength={CODE_LENGTH}
        style={styles.code}
        error={message}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  intro: {
    gap: space.sm,
  },
  start: {
    alignSelf: 'flex-start',
    paddingHorizontal: 0,
  },
  code: {
    fontSize: 24,
    letterSpacing: 8,
    textAlign: 'center',
  },
});
