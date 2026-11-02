import {
  EditOutlined,
  KeyOutlined,
  PlusOutlined,
  StopOutlined,
  UnlockOutlined,
} from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Card, Popconfirm, Segmented, Space, Tag, Tooltip } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { $api, api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { AdminUser } from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { adminAccess } from '../../auth/roles';
import { apiErrorMessage } from '../../components/ApiErrorAlert';
import { CursorTable } from '../../components/CursorTable';
import { DateTimeText, Ltr } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag } from '../../components/StatusTag';
import { formatSaudiMobile } from '../../lib/phone';
import { AdminFormModal, ResetPasswordModal } from './AdminModals';

type StatusFilter = 'all' | 'active' | 'blocked';

export function AdminsPage() {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const { admin: me } = useAuth();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const status = (params.get('status') as StatusFilter | null) ?? 'all';
  const query = cleanQuery({ status: status === 'all' ? undefined : status });
  const list = useCursorList(['get', '/admin/admins', query], (cursor, signal) =>
    api.GET('/admin/admins', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );
  const roles = $api.useQuery('get', '/admin/roles', undefined, { staleTime: 5 * 60_000 });
  const [editing, setEditing] = useState<AdminUser | 'new' | null>(null);
  const [resetting, setResetting] = useState<AdminUser | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const setStatus = async (target: AdminUser, next: AdminUser['status']) => {
    setBusyId(target.id);
    try {
      await api.PATCH('/admin/admins/{admin_id}', {
        params: { path: { admin_id: target.id } },
        body: { status: next },
      });
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/admins'] });
      message.success(t('common.saved'));
    } catch (e) {
      message.error(apiErrorMessage(t, e));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <>
      <PageHeader
        title={t('nav.admins')}
        subtitle={t('admins.subtitle')}
        extra={
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditing('new')}>
            {t('admins.newAdmin')}
          </Button>
        }
      />
      <Card>
        <Segmented<StatusFilter>
          style={{ marginBottom: 16 }}
          value={status}
          onChange={(value) => {
            const next = new URLSearchParams(params);
            if (value === 'all') next.delete('status');
            else next.set('status', value);
            setParams(next, { replace: true });
          }}
          options={[
            { value: 'all', label: t('common.all') },
            { value: 'active', label: t('status.admin.active') },
            { value: 'blocked', label: t('status.admin.blocked') },
          ]}
        />
        <CursorTable<AdminUser>
          list={list}
          columns={[
            {
              title: t('fields.name'),
              dataIndex: 'name',
              render: (name: string, a) => (
                <Space size={6}>
                  {name}
                  {a.id === me?.id && <Tag color="blue">{t('admins.you')}</Tag>}
                </Space>
              ),
            },
            { title: t('auth.email'), dataIndex: 'email', render: (v: string) => <Ltr>{v}</Ltr> },
            {
              title: t('admins.phone'),
              dataIndex: 'phone',
              render: (v: string) => <Ltr>{formatSaudiMobile(v)}</Ltr>,
            },
            {
              title: t('admins.roles'),
              dataIndex: 'roles',
              render: (value: AdminUser['roles']) => (
                <Space wrap size={4}>
                  {value.map((r) => (
                    <Tag key={r}>{t(`roles.${r}`)}</Tag>
                  ))}
                </Space>
              ),
            },
            {
              title: t('common.status'),
              dataIndex: 'status',
              render: (v: string) => <StatusTag kind="admin" value={v} />,
            },
            {
              title: t('admins.lastLogin'),
              dataIndex: 'last_login_at',
              render: (v: string | null) => (v ? <DateTimeText value={v} /> : t('admins.never')),
            },
            {
              title: '',
              key: 'actions',
              render: (_, a) => {
                const access = adminAccess(me, a);
                const busy = busyId === a.id;
                return (
                  <Space size={4} wrap onClick={(e) => e.stopPropagation()}>
                    {access === 'block_only' ? (
                      <Tooltip title={t('admins.blockOnly')}>
                        <Button
                          size="small"
                          icon={<EditOutlined />}
                          disabled
                          aria-label={t('common.edit')}
                        />
                      </Tooltip>
                    ) : (
                      <Button
                        size="small"
                        icon={<EditOutlined />}
                        aria-label={t('common.edit')}
                        onClick={() => setEditing(a)}
                      />
                    )}
                    {access === 'full' && (
                      <Tooltip title={t('admins.resetPassword')}>
                        <Button
                          size="small"
                          icon={<KeyOutlined />}
                          aria-label={t('admins.resetPassword')}
                          onClick={() => setResetting(a)}
                        />
                      </Tooltip>
                    )}
                    {access !== 'self' && a.status === 'active' && (
                      <Popconfirm
                        title={t('admins.blockConfirm', { name: a.name })}
                        okButtonProps={{ danger: true }}
                        onConfirm={() => setStatus(a, 'blocked')}
                      >
                        <Button size="small" danger icon={<StopOutlined />} loading={busy}>
                          {t('admins.block')}
                        </Button>
                      </Popconfirm>
                    )}
                    {access === 'full' && a.status === 'blocked' && (
                      <Popconfirm
                        title={t('admins.unblockConfirm', { name: a.name })}
                        onConfirm={() => setStatus(a, 'active')}
                      >
                        <Button size="small" icon={<UnlockOutlined />} loading={busy}>
                          {t('admins.unblock')}
                        </Button>
                      </Popconfirm>
                    )}
                  </Space>
                );
              },
            },
          ]}
        />
      </Card>
      {editing && (
        <AdminFormModal
          admin={editing === 'new' ? null : editing}
          roles={roles.data?.data ?? []}
          rolesError={roles.error}
          onClose={() => setEditing(null)}
        />
      )}
      {resetting && <ResetPasswordModal admin={resetting} onClose={() => setResetting(null)} />}
    </>
  );
}
