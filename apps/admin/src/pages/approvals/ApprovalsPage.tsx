import { CheckOutlined, CloseOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Card, Popconfirm, Segmented, Space, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { unwrap, useCursorList } from '../../api/lists';
import type { ApprovalRequest, ApprovalStatus } from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { apiErrorMessage } from '../../components/ApiErrorAlert';
import { CursorTable } from '../../components/CursorTable';
import { ActorText, DateTimeText } from '../../components/format';
import { JsonBlock } from '../../components/JsonBlock';
import { PageHeader } from '../../components/PageHeader';
import { ReasonModal } from '../../components/ReasonModal';
import { StatusTag } from '../../components/StatusTag';
import { toApiError } from '../../api/errors';

const STATUSES: ApprovalStatus[] = ['pending', 'approved', 'rejected', 'cancelled'];

/** Where the subject of an approval request lives in the portal, when it has a page. */
function subjectLink(request: ApprovalRequest): string | null {
  switch (request.subject_type) {
    case 'campaign':
      return `/campaigns/${request.subject_id}`;
    case 'payment':
    case 'payment_authorization':
      return `/payments/${request.subject_id}`;
    case 'ledger_adjustment':
      return '/ledger?tab=adjustments';
    case 'customer_order':
    case 'order':
      return `/orders/${request.subject_id}`;
    case 'refund':
      return '/refunds';
    default:
      return null;
  }
}

/** Money-moving actions waiting for a second admin. Only finance may decide, and never on their own request. */
export function ApprovalsPage() {
  const { t } = useTranslation();
  const { admin, can } = useAuth();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const status = (params.get('status') as ApprovalStatus | null) ?? 'pending';
  const query = { status };
  const list = useCursorList(['get', '/admin/approval-requests', query], (cursor, signal) =>
    api
      .GET('/admin/approval-requests', { params: { query: { ...query, cursor } }, signal })
      .then(unwrap),
  );
  const [rejecting, setRejecting] = useState<ApprovalRequest | null>(null);
  const [approving, setApproving] = useState<string | null>(null);

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/approval-requests'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/refunds'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/campaigns/{campaign_id}'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/payments/{payment_id}'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/ledger/adjustments'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/ledger/transactions'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/ledger/accounts'] }),
    ]);

  const approve = async (request: ApprovalRequest) => {
    setApproving(request.id);
    try {
      await api.POST('/admin/approval-requests/{approval_request_id}/approve', {
        params: { path: { approval_request_id: request.id } },
      });
      await refresh();
      message.success(t('approvals.approved'));
    } catch (e) {
      message.error(
        toApiError(e).code === 'same_admin' ? t('approvals.sameAdmin') : apiErrorMessage(t, e),
      );
    } finally {
      setApproving(null);
    }
  };

  const canDecide = can('approvals.decide');

  return (
    <>
      <PageHeader title={t('nav.approvals')} subtitle={t('approvals.subtitle')} />
      <Card>
        <Segmented
          style={{ marginBottom: 16 }}
          value={status}
          onChange={(v) => setParams({ status: String(v) }, { replace: true })}
          options={STATUSES.map((s) => ({ value: s, label: t(`status.approval.${s}`) }))}
        />
        <CursorTable<ApprovalRequest>
          list={list}
          expandable={{
            expandedRowRender: (r) => <JsonBlock value={r.payload} />,
            rowExpandable: (r) => Object.keys(r.payload ?? {}).length > 0,
          }}
          columns={[
            {
              title: t('approvals.type'),
              dataIndex: 'type',
              render: (v: ApprovalRequest['type']) => t(`approvals.types.${v}`),
            },
            {
              title: t('approvals.subject'),
              key: 'subject',
              render: (_, r) => {
                const to = subjectLink(r);
                const label = t(`approvals.subjects.${r.subject_type}` as never, {
                  defaultValue: r.subject_type,
                });
                return to ? <Link to={to}>{label}</Link> : label;
              },
            },
            {
              title: t('payments.requestedBy'),
              key: 'by',
              render: (_, r) => <ActorText actor={r.requested_by} />,
            },
            {
              title: t('common.createdAt'),
              dataIndex: 'created_at',
              render: (v: string) => <DateTimeText value={v} />,
            },
            {
              title: t('common.status'),
              dataIndex: 'status',
              render: (v: ApprovalStatus) => <StatusTag kind="approval" value={v} />,
            },
            {
              title: t('approvals.decidedBy'),
              key: 'decided',
              render: (_, r) =>
                r.decided_by ? (
                  <>
                    <ActorText actor={r.decided_by} />
                    <br />
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                      <DateTimeText value={r.decided_at} />
                      {r.reason ? ` · ${r.reason}` : ''}
                    </Typography.Text>
                  </>
                ) : (
                  '—'
                ),
            },
            ...(canDecide && status === 'pending'
              ? [
                  {
                    key: 'actions',
                    render: (_: unknown, r: ApprovalRequest) => {
                      const own = r.requested_by.id !== null && r.requested_by.id === admin?.id;
                      return (
                        <Tooltip title={own ? t('approvals.sameAdmin') : undefined}>
                          <Space>
                            <Popconfirm
                              title={t('approvals.approveConfirm')}
                              disabled={own}
                              onConfirm={() => approve(r)}
                            >
                              <Button
                                size="small"
                                type="primary"
                                icon={<CheckOutlined />}
                                disabled={own}
                                loading={approving === r.id}
                              >
                                {t('approvals.approve')}
                              </Button>
                            </Popconfirm>
                            <Button
                              size="small"
                              danger
                              icon={<CloseOutlined />}
                              disabled={own}
                              onClick={() => setRejecting(r)}
                            >
                              {t('approvals.reject')}
                            </Button>
                          </Space>
                        </Tooltip>
                      );
                    },
                  },
                ]
              : []),
          ]}
        />
      </Card>
      <ReasonModal
        open={rejecting !== null}
        title={t('approvals.rejectTitle')}
        danger
        onCancel={() => setRejecting(null)}
        onSubmit={async (reason) => {
          if (!rejecting) return;
          await api.POST('/admin/approval-requests/{approval_request_id}/reject', {
            params: { path: { approval_request_id: rejecting.id } },
            body: { reason },
          });
          await refresh();
          message.success(t('approvals.rejected'));
          setRejecting(null);
        }}
      />
    </>
  );
}
