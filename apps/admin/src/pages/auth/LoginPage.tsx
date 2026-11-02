import { LockOutlined, MailOutlined, SafetyOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Flex, Form, Input, Segmented, Space, Typography } from 'antd';
import { useEffect, useState } from 'react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { api } from '../../api/client';
import { toApiError } from '../../api/errors';
import type { OtpChallenge } from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { apiErrorMessage } from '../../components/ApiErrorAlert';
import { Ltr } from '../../components/format';
import { useLocale } from '../../i18n/LocaleProvider';
import { normalizeDigits } from '../../lib/money';
import { formatDateTime } from '../../lib/time';

type Credentials = { email: string; password: string };

/** Admin sign-in: email and password, then the 6-digit code sent to the admin's phone. */
export function LoginPage() {
  const { t } = useTranslation();
  const { token, signIn } = useAuth();
  const { locale, setLocale } = useLocale();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from ?? '/';

  const [challenge, setChallenge] = useState<OtpChallenge | null>(null);
  const [credentials, setCredentials] = useState<Credentials | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (!challenge) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [challenge]);

  if (token) return <Navigate to={from} replace />;

  const requestCode = async (values: Credentials) => {
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.POST('/admin/auth/login', { body: values });
      setCredentials(values);
      setChallenge(data!.data);
    } catch (e) {
      setError(loginErrorMessage(t, e));
    } finally {
      setBusy(false);
    }
  };

  const verify = async ({ code }: { code: string }) => {
    if (!challenge) return;
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.POST('/admin/auth/verify', {
        body: { challenge_id: challenge.challenge_id, code: normalizeDigits(code).trim() },
      });
      signIn(data!.data);
      navigate(from, { replace: true });
    } catch (e) {
      setError(loginErrorMessage(t, e));
    } finally {
      setBusy(false);
    }
  };

  const resendIn = challenge
    ? Math.max(0, Math.ceil((new Date(challenge.resend_available_at).getTime() - now) / 1000))
    : 0;

  return (
    <Flex align="center" justify="center" style={{ minHeight: '100vh', padding: 16 }}>
      <Card style={{ width: '100%', maxWidth: 400 }}>
        <Flex justify="space-between" align="center" style={{ marginBottom: 16 }}>
          <Typography.Title level={4} style={{ margin: 0 }}>
            {t('app.name')}
          </Typography.Title>
          <Segmented
            size="small"
            value={locale}
            onChange={(value) => setLocale(value as 'ar' | 'en')}
            options={[
              { value: 'ar', label: 'ع' },
              { value: 'en', label: 'EN' },
            ]}
          />
        </Flex>

        {error && <Alert type="error" showIcon title={error} style={{ marginBottom: 16 }} />}

        {!challenge ? (
          <Form<Credentials>
            layout="vertical"
            onFinish={(v) => void requestCode(v)}
            requiredMark={false}
          >
            <Typography.Paragraph type="secondary">{t('auth.step1')}</Typography.Paragraph>
            <Form.Item
              name="email"
              label={t('auth.email')}
              rules={[
                { required: true, message: t('validation.required') },
                { type: 'email', message: t('validation.email') },
              ]}
            >
              <Input prefix={<MailOutlined />} autoComplete="username" dir="ltr" autoFocus />
            </Form.Item>
            <Form.Item
              name="password"
              label={t('auth.password')}
              rules={[{ required: true, message: t('validation.required') }]}
            >
              <Input.Password prefix={<LockOutlined />} autoComplete="current-password" dir="ltr" />
            </Form.Item>
            <Button type="primary" htmlType="submit" block loading={busy}>
              {t('auth.continue')}
            </Button>
          </Form>
        ) : (
          <Form<{ code: string }>
            layout="vertical"
            onFinish={(v) => void verify(v)}
            requiredMark={false}
          >
            <Typography.Paragraph type="secondary">
              {t('auth.step2', { time: formatDateTime(challenge.expires_at) })}
            </Typography.Paragraph>
            <Form.Item
              name="code"
              label={t('auth.code')}
              normalize={(value: string) => normalizeDigits(value).replace(/\D/g, '').slice(0, 6)}
              rules={[
                { required: true, message: t('validation.required') },
                { pattern: /^[0-9]{6}$/, message: t('auth.codeFormat') },
              ]}
            >
              <Input
                prefix={<SafetyOutlined />}
                inputMode="numeric"
                autoComplete="one-time-code"
                dir="ltr"
                maxLength={6}
                autoFocus
              />
            </Form.Item>
            <Space orientation="vertical" style={{ width: '100%' }}>
              <Button type="primary" htmlType="submit" block loading={busy}>
                {t('auth.signIn')}
              </Button>
              <Flex justify="space-between">
                <Button
                  type="link"
                  style={{ padding: 0 }}
                  onClick={() => {
                    setChallenge(null);
                    setError(null);
                  }}
                >
                  {t('auth.useAnotherAccount')}
                </Button>
                <Button
                  type="link"
                  style={{ padding: 0 }}
                  disabled={resendIn > 0 || busy || !credentials}
                  onClick={() => credentials && void requestCode(credentials)}
                >
                  {resendIn > 0 ? (
                    <>
                      {t('auth.resendIn')} <Ltr>{resendIn}</Ltr>
                    </>
                  ) : (
                    t('auth.resend')
                  )}
                </Button>
              </Flex>
            </Space>
          </Form>
        )}
      </Card>
    </Flex>
  );
}

function loginErrorMessage(t: TFunction, error: unknown): string {
  const apiError = toApiError(error);
  switch (apiError.code) {
    case 'invalid_credentials':
      return t('auth.errors.invalidCredentials');
    case 'account_blocked':
      return t('auth.errors.accountBlocked');
    case 'otp_invalid':
      return t('auth.errors.otpInvalid');
    case 'otp_expired':
      return t('auth.errors.otpExpired');
    case 'otp_attempts_exhausted':
      return t('auth.errors.otpAttemptsExhausted');
  }
  if (apiError.status === 429) return t('auth.errors.tooManyRequests');
  return apiErrorMessage(t, apiError);
}
