import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Redirect, router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { api } from '@/api/endpoints';
import { isApiError } from '@/api/errors';
import { queryKeys, useMe } from '@/api/queries';
import type { User } from '@/api/types';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Notice } from '@/components/Notice';
import { Screen } from '@/components/Screen';
import { ErrorState, LoadingState } from '@/components/States';
import { Text } from '@/components/Text';
import { TextField } from '@/components/TextField';
import { useFormat } from '@/i18n/useFormat';
import { safeNextPath } from '@/lib/navigation';
import { formatSaudiMobile } from '@/lib/phone';
import { space } from '@/theme/tokens';

export default function ProfileScreen() {
  const { isSignedIn } = useAuth();
  const me = useMe(isSignedIn);
  if (!isSignedIn) return <Redirect href="/sign-in" />;
  if (me.isError) return <ErrorState error={me.error} onRetry={() => void me.refetch()} />;
  if (!me.data) return <LoadingState />;
  return <ProfileForm user={me.data} />;
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function ProfileForm({ user }: { user: User }) {
  const { t } = useFormat();
  const queryClient = useQueryClient();
  const params = useLocalSearchParams<{ welcome?: string; next?: string }>();
  const welcome = params.welcome === '1';
  const next = safeNextPath(params.next);
  const [name, setName] = useState(user.name ?? '');
  const [email, setEmail] = useState(user.email ?? '');
  const [touched, setTouched] = useState(false);
  const emailInvalid = email.trim() !== '' && !EMAIL.test(email.trim());

  const leave = () => {
    if (next) router.replace(next);
    else if (welcome) router.replace('/');
    else router.back();
  };

  const save = useMutation({
    mutationFn: () => api.updateMe({ name: name.trim() || null, email: email.trim() || null }),
    onSuccess: (updated) => {
      queryClient.setQueryData(queryKeys.me, updated);
      if (welcome) leave();
    },
  });

  const submit = () => {
    setTouched(true);
    if (!emailInvalid) save.mutate();
  };

  const fieldError = (field: string) =>
    isApiError(save.error) ? save.error.fieldError(field) : undefined;

  return (
    <Screen
      footer={
        <>
          <Button
            testID="save-profile"
            title={t('common.save')}
            loading={save.isPending}
            onPress={submit}
          />
          {welcome ? <Button variant="ghost" title={t('profile.skip')} onPress={leave} /> : null}
        </>
      }>
      <Stack.Screen options={{ title: welcome ? t('profile.welcomeTitle') : t('profile.title') }} />
      {welcome ? (
        <View style={styles.intro}>
          <Text variant="title">{t('profile.welcomeTitle')}</Text>
          <Text color="textMuted">{t('profile.welcomeBody')}</Text>
        </View>
      ) : null}
      {save.isSuccess && !welcome ? <Notice tone="success" message={t('profile.saved')} /> : null}
      {save.error && !isApiError(save.error) ? (
        <Notice tone="danger" message={t('errors.generic')} />
      ) : null}
      <TextField
        label={t('profile.name')}
        placeholder={t('profile.namePlaceholder')}
        value={name}
        onChangeText={setName}
        autoComplete="name"
        textContentType="name"
        maxLength={100}
        error={fieldError('name')}
      />
      <TextField
        label={t('profile.email')}
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        textContentType="emailAddress"
        maxLength={191}
        ltr
        optional
        error={(touched && emailInvalid ? t('validation.email') : undefined) ?? fieldError('email')}
      />
      <TextField
        label={t('profile.phone')}
        value={formatSaudiMobile(user.phone, { isolate: false })}
        editable={false}
        hint={t('profile.phoneHint')}
        ltr
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  intro: {
    gap: space.sm,
  },
});
