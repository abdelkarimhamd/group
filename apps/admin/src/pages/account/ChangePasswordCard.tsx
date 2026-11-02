import { App, Button, Card, Form, Input, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import { toApiError } from '../../api/errors';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { applyFieldErrors, passwordRules, requiredRule } from '../../components/forms';
import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from '../../lib/password';

type Values = { current_password: string; password: string; repeat: string };

/** POST /admin/me/password: signs out the admin's other sessions, keeps this one. */
export function ChangePasswordCard() {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const [form] = Form.useForm<Values>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (v: Values) => {
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/me/password', {
        body: { current_password: v.current_password, password: v.password },
      });
      form.resetFields();
      message.success(t('account.passwordChanged'));
    } catch (e) {
      if (toApiError(e).code === 'invalid_credentials') {
        form.setFields([{ name: 'current_password', errors: [t('account.wrongPassword')] }]);
      } else if (!applyFieldErrors(form, e)) {
        setError(e);
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title={t('account.changePassword')}>
      <Typography.Paragraph type="secondary">
        {t('account.changePasswordHelp', { n: PASSWORD_MIN_LENGTH })}
      </Typography.Paragraph>
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form<Values>
        form={form}
        layout="vertical"
        style={{ maxWidth: 420 }}
        onFinish={(v) => void submit(v)}
      >
        <Form.Item
          name="current_password"
          label={t('account.currentPassword')}
          rules={[requiredRule(t)]}
        >
          <Input.Password autoComplete="current-password" maxLength={PASSWORD_MAX_LENGTH} />
        </Form.Item>
        <Form.Item name="password" label={t('account.newPassword')} rules={passwordRules(t)}>
          <Input.Password autoComplete="new-password" maxLength={PASSWORD_MAX_LENGTH} />
        </Form.Item>
        <Form.Item
          name="repeat"
          label={t('account.repeatPassword')}
          dependencies={['password']}
          rules={[
            requiredRule(t),
            ({ getFieldValue }) => ({
              validator: async (_, value: string | undefined) => {
                if (value && value !== getFieldValue('password'))
                  throw new Error(t('validation.passwordsDiffer'));
              },
            }),
          ]}
        >
          <Input.Password autoComplete="new-password" maxLength={PASSWORD_MAX_LENGTH} />
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={busy}>
          {t('account.changePassword')}
        </Button>
      </Form>
    </Card>
  );
}
