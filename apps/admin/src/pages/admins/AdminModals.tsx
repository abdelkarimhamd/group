import { useQueryClient } from '@tanstack/react-query';
import { Alert, App, Button, Checkbox, Form, Input, Modal, Space, Tooltip, Typography } from 'antd';
import type { FormInstance } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type {
  AdminCreateInput,
  AdminRole,
  AdminRoleName,
  AdminUpdateInput,
  AdminUser,
} from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { adminAccess } from '../../auth/roles';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { applyFieldErrors, passwordRules, phoneRules, requiredRule } from '../../components/forms';
import { generatePassword, PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from '../../lib/password';
import { normalizeSaudiMobile } from '../../lib/phone';

type FormValues = {
  email: string;
  name: string;
  phone: string;
  roles: AdminRoleName[];
  password?: string;
};

/** Add an admin, or change one's name, phone and roles. */
export function AdminFormModal({
  admin,
  roles,
  rolesError,
  onClose,
}: {
  admin: AdminUser | null;
  roles: AdminRole[];
  rolesError: unknown;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const { admin: me, reload } = useAuth();
  const queryClient = useQueryClient();
  const [form] = Form.useForm<FormValues>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const access = admin ? adminAccess(me, admin) : 'full';
  const isSelf = access === 'self';

  const submit = async () => {
    const v = await form.validateFields();
    const phone = normalizeSaudiMobile(v.phone) ?? v.phone;
    setBusy(true);
    setError(null);
    try {
      if (!admin) {
        const body: AdminCreateInput = {
          email: v.email.trim(),
          name: v.name.trim(),
          phone,
          roles: v.roles,
          password: v.password ?? '',
        };
        await api.POST('/admin/admins', { body });
        message.success(t('admins.created'));
      } else {
        const body = changesOf(admin, { ...v, phone }, isSelf);
        if (body) {
          await api.PATCH('/admin/admins/{admin_id}', {
            params: { path: { admin_id: admin.id } },
            body,
          });
          if (isSelf) reload();
        }
        message.success(t('common.saved'));
      }
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/admins'] });
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      title={admin ? t('admins.editAdmin') : t('admins.newAdmin')}
      okText={t('common.save')}
      confirmLoading={busy}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      {isSelf && (
        <Alert type="info" showIcon title={t('admins.selfLimits')} style={{ marginBottom: 16 }} />
      )}
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form<FormValues>
        form={form}
        layout="vertical"
        initialValues={
          admin
            ? { email: admin.email, name: admin.name, phone: admin.phone, roles: admin.roles }
            : { roles: [] }
        }
      >
        <Form.Item
          name="email"
          label={t('auth.email')}
          rules={admin ? [] : [requiredRule(t), { type: 'email', message: t('validation.email') }]}
        >
          <Input
            dir="ltr"
            type="email"
            autoComplete="off"
            maxLength={191}
            disabled={admin !== null}
          />
        </Form.Item>
        <Form.Item name="name" label={t('fields.name')} rules={[requiredRule(t), { max: 100 }]}>
          <Input maxLength={100} />
        </Form.Item>
        <Form.Item
          name="phone"
          label={t('admins.phone')}
          rules={phoneRules(t)}
          extra={
            admin && !isSelf
              ? `${t('admins.phoneHelp')} ${t('admins.phoneChangeSignsOut')}`
              : t('admins.phoneHelp')
          }
        >
          <Input dir="ltr" inputMode="tel" placeholder="05x xxx xxxx" autoComplete="off" />
        </Form.Item>
        <Form.Item
          name="roles"
          label={t('admins.roles')}
          extra={t('admins.rolesHelp')}
          rules={[{ required: true, type: 'array', min: 1, message: t('validation.required') }]}
        >
          <RoleChecklist roles={roles} disabled={isSelf} />
        </Form.Item>
        {rolesError ? <ApiErrorAlert error={rolesError} /> : null}
        {!admin && (
          <Form.Item
            name="password"
            label={t('admins.password')}
            rules={passwordRules(t)}
            extra={t('admins.passwordHelp', { n: PASSWORD_MIN_LENGTH })}
          >
            <PasswordWithGenerate form={form} />
          </Form.Item>
        )}
      </Form>
    </Modal>
  );
}

/** Only the fields that changed; null when nothing did. */
function changesOf(admin: AdminUser, v: FormValues, isSelf: boolean): AdminUpdateInput | null {
  const body: AdminUpdateInput = {};
  if (v.name.trim() !== admin.name) body.name = v.name.trim();
  if (v.phone !== admin.phone) body.phone = v.phone;
  const sameRoles =
    v.roles.length === admin.roles.length && v.roles.every((r) => admin.roles.includes(r));
  if (!isSelf && !sameRoles) body.roles = v.roles;
  return Object.keys(body).length > 0 ? body : null;
}

/** Checkboxes for the roles, greying out the ones the signed-in admin cannot grant. */
function RoleChecklist({
  roles,
  disabled,
  value,
  onChange,
}: {
  roles: AdminRole[];
  disabled: boolean;
  value?: AdminRoleName[];
  onChange?: (value: AdminRoleName[]) => void;
}) {
  const { t } = useTranslation();
  return (
    <Checkbox.Group<AdminRoleName>
      value={value}
      onChange={onChange}
      disabled={disabled}
      style={{ display: 'flex', flexDirection: 'column', gap: 6 }}
    >
      {roles.map((role) => {
        const blocked = !role.grantable && !value?.includes(role.name);
        return (
          <Tooltip
            key={role.name}
            placement="right"
            title={
              <>
                <Typography.Text strong style={{ color: 'inherit' }}>
                  {blocked ? t('admins.roleNotGrantable') : t('admins.rolePermissions')}
                </Typography.Text>
                <ul style={{ margin: '4px 0 0', paddingInlineStart: 18 }}>
                  {role.permissions.map((p) => (
                    <li key={p}>{t(`permissions.${p}`)}</li>
                  ))}
                </ul>
              </>
            }
          >
            <span style={{ alignSelf: 'flex-start' }}>
              <Checkbox value={role.name} disabled={disabled || !role.grantable}>
                {t(`roles.${role.name}`)}
              </Checkbox>
            </span>
          </Tooltip>
        );
      })}
    </Checkbox.Group>
  );
}

/** A password input with a button that fills in a strong random one and shows it. */
function PasswordWithGenerate({
  form,
  id,
  value,
  onChange,
}: {
  form: FormInstance;
  id?: string;
  value?: string;
  onChange?: (value: string) => void;
}) {
  const { t } = useTranslation();
  const [visible, setVisible] = useState(false);
  return (
    <Space.Compact style={{ width: '100%' }}>
      <Input.Password
        id={id}
        dir="ltr"
        autoComplete="new-password"
        maxLength={PASSWORD_MAX_LENGTH}
        value={value}
        onChange={(e) => onChange?.(e.target.value)}
        visibilityToggle={{ visible, onVisibleChange: setVisible }}
      />
      <Button
        onClick={() => {
          onChange?.(generatePassword());
          setVisible(true);
          void form.validateFields(['password']).catch(() => undefined);
        }}
      >
        {t('admins.generate')}
      </Button>
    </Space.Compact>
  );
}

/** Set a new password for an admin who forgot theirs. Signs them out everywhere. */
export function ResetPasswordModal({ admin, onClose }: { admin: AdminUser; onClose: () => void }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const [form] = Form.useForm<{ password: string }>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.PATCH('/admin/admins/{admin_id}', {
        params: { path: { admin_id: admin.id } },
        body: { password: v.password },
      });
      message.success(t('common.saved'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      title={`${t('admins.resetPassword')}: ${admin.name}`}
      okText={t('common.save')}
      confirmLoading={busy}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Typography.Paragraph type="secondary">{t('admins.resetPasswordHelp')}</Typography.Paragraph>
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form form={form} layout="vertical">
        <Form.Item
          name="password"
          label={t('admins.password')}
          rules={passwordRules(t)}
          extra={t('admins.passwordHelp', { n: PASSWORD_MIN_LENGTH })}
        >
          <PasswordWithGenerate form={form} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
