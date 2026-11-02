import {
  CheckOutlined,
  ClockCircleOutlined,
  CloseOutlined,
  EditOutlined,
  SendOutlined,
  ShopOutlined,
  StopOutlined,
  WarningOutlined,
} from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import {
  Alert,
  App,
  Button,
  Card,
  Col,
  Form,
  Modal,
  Popconfirm,
  Row,
  Space,
  Statistic,
  Steps,
  Tabs,
  Tag,
} from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { $api, api } from '../../api/client';
import { toApiError } from '../../api/errors';
import type { AdminCampaign, CampaignAction, ClosingStep } from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { ApiErrorAlert, apiErrorMessage } from '../../components/ApiErrorAlert';
import { Fields } from '../../components/Fields';
import { ActorText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { applyFieldErrors } from '../../components/forms';
import { PageHeader } from '../../components/PageHeader';
import { ReasonModal } from '../../components/ReasonModal';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';
import { OrdersTable } from '../orders/OrdersPage';
import { PaymentsTable } from '../payments/PaymentsPage';
import {
  ApproveModal,
  BelowTargetModal,
  ExtendModal,
  isApprovalRequest,
  SupplierDecisionModal,
} from './CampaignActionModals';
import {
  CampaignForm,
  campaignFormValues,
  campaignInputOf,
  type CampaignFormValues,
} from './CampaignForm';
import { CampaignProgress } from './CampaignProgress';
import { EventsTab } from './EventsTab';
import { MarginTab } from './MarginTab';
import { ParticipationsTab } from './ParticipationsTab';
import { TiersTab } from './TiersTab';

type OpenModal = Exclude<CampaignAction, 'edit_tiers' | 'submit'> | null;

const CLOSING_STEPS: ClosingStep[] = [
  'settling_joins',
  'awaiting_supplier',
  'capturing',
  'notifying',
  'done',
];

export function CampaignDetailPage() {
  const { campaignId = '' } = useParams();
  const { t } = useTranslation();
  const pick = useBilingual();
  const { can } = useAuth();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [modal, setModal] = useState<OpenModal>(null);
  const [submitting, setSubmitting] = useState(false);
  const campaignQuery = $api.useQuery('get', '/admin/campaigns/{campaign_id}', {
    params: { path: { campaign_id: campaignId } },
  });

  const c = campaignQuery.data?.data;
  if (!c) return <QueryState isPending={campaignQuery.isPending} error={campaignQuery.error} />;

  const has = (action: CampaignAction) => c.available_actions.includes(action);

  const invalidate = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/campaigns'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/campaigns/{campaign_id}'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/campaigns/{campaign_id}/events'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/campaigns/{campaign_id}/margin'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/approval-requests'] }),
    ]);
  };

  const submit = async () => {
    setSubmitting(true);
    try {
      await api.POST('/admin/campaigns/{campaign_id}/submit', {
        params: { path: { campaign_id: c.id } },
      });
      await invalidate();
      message.success(t('campaigns.submitted'));
    } catch (e) {
      const code = toApiError(e).code;
      message.error(
        code === 'tiers_missing' ||
          code === 'landed_cost_missing' ||
          code === 'supplier_not_approved'
          ? t(`campaigns.submitErrors.${code}`)
          : apiErrorMessage(t, e),
      );
    } finally {
      setSubmitting(false);
    }
  };

  const reasonAction = async (action: 'reject' | 'cancel', reason: string) => {
    const path = { params: { path: { campaign_id: c.id } }, body: { reason } };
    if (action === 'reject') {
      await api.POST('/admin/campaigns/{campaign_id}/reject', path);
      message.success(t('campaigns.rejected'));
    } else {
      const { data } = await api.POST('/admin/campaigns/{campaign_id}/cancel', path);
      message.success(
        isApprovalRequest(data?.data) ? t('campaigns.sentForApproval') : t('campaigns.cancelled'),
      );
    }
    await invalidate();
    setModal(null);
  };

  const tab = params.get('tab') ?? 'overview';
  const modalProps = { campaign: c, onClose: () => setModal(null), onDone: invalidate };

  return (
    <>
      <PageHeader
        backTo="/campaigns"
        title={pick(c, 'title')}
        subtitle={<Ltr>{c.code}</Ltr>}
        tags={
          <>
            <StatusTag kind="campaign" value={c.status} />
            {c.closing_step && c.closing_step !== 'done' && (
              <StatusTag kind="closingStep" value={c.closing_step} />
            )}
          </>
        }
        extra={
          <>
            {has('record_supplier_decision') && (
              <Button
                type="primary"
                icon={<ShopOutlined />}
                onClick={() => setModal('record_supplier_decision')}
              >
                {t('campaigns.actions.record_supplier_decision')}
              </Button>
            )}
            {has('decide_below_target') && (
              <Button
                type="primary"
                icon={<WarningOutlined />}
                onClick={() => setModal('decide_below_target')}
              >
                {t('campaigns.actions.decide_below_target')}
              </Button>
            )}
            {has('approve') && (
              <Button type="primary" icon={<CheckOutlined />} onClick={() => setModal('approve')}>
                {t('campaigns.actions.approve')}
              </Button>
            )}
            {has('submit') && (
              <Popconfirm title={t('campaigns.submitConfirm')} onConfirm={() => submit()}>
                <Button type="primary" icon={<SendOutlined />} loading={submitting}>
                  {t('campaigns.actions.submit')}
                </Button>
              </Popconfirm>
            )}
            {has('edit') && (
              <Button icon={<EditOutlined />} onClick={() => setModal('edit')}>
                {t('campaigns.actions.edit')}
              </Button>
            )}
            {has('extend') && (
              <Button icon={<ClockCircleOutlined />} onClick={() => setModal('extend')}>
                {t('campaigns.actions.extend')}
              </Button>
            )}
            {has('reject') && (
              <Button icon={<CloseOutlined />} onClick={() => setModal('reject')}>
                {t('campaigns.actions.reject')}
              </Button>
            )}
            {has('cancel') && (
              <Button danger icon={<StopOutlined />} onClick={() => setModal('cancel')}>
                {t('campaigns.actions.cancel')}
              </Button>
            )}
          </>
        }
      />

      <AttentionBanner campaign={c} />

      <Tabs
        activeKey={tab}
        onChange={(key) => {
          const next = new URLSearchParams(params);
          next.set('tab', key);
          setParams(next, { replace: true });
        }}
        items={[
          {
            key: 'overview',
            label: t('campaigns.tabs.overview'),
            children: <Overview campaign={c} />,
          },
          {
            key: 'tiers',
            label: t('campaigns.tabs.tiers'),
            children: <TiersTab campaign={c} invalidate={invalidate} />,
          },
          {
            key: 'margin',
            label: t('campaigns.tabs.margin'),
            children: <MarginTab campaign={c} />,
          },
          {
            key: 'participants',
            label: t('campaigns.tabs.participants'),
            children: <ParticipationsTab campaignId={c.id} />,
          },
          ...(can('payments.view')
            ? [
                {
                  key: 'payments',
                  label: t('campaigns.tabs.payments'),
                  children: (
                    <Card>
                      <PaymentsTable query={{ campaign_id: c.id }} />
                    </Card>
                  ),
                },
              ]
            : []),
          ...(can('orders.view')
            ? [
                {
                  key: 'orders',
                  label: t('campaigns.tabs.orders'),
                  children: (
                    <Card>
                      <OrdersTable query={{ campaign_id: c.id }} />
                    </Card>
                  ),
                },
              ]
            : []),
          {
            key: 'history',
            label: t('campaigns.tabs.history'),
            children: <EventsTab campaignId={c.id} />,
          },
        ]}
      />

      {modal === 'edit' && <EditCampaignModal {...modalProps} />}
      {modal === 'approve' && <ApproveModal {...modalProps} />}
      {modal === 'extend' && <ExtendModal {...modalProps} />}
      {modal === 'record_supplier_decision' && <SupplierDecisionModal {...modalProps} />}
      {modal === 'decide_below_target' && <BelowTargetModal {...modalProps} />}
      <ReasonModal
        open={modal === 'reject'}
        title={t('campaigns.rejectTitle')}
        description={t('campaigns.rejectHelp')}
        onCancel={() => setModal(null)}
        onSubmit={(reason) => reasonAction('reject', reason)}
      />
      <ReasonModal
        open={modal === 'cancel'}
        danger
        title={t('campaigns.cancelTitle')}
        description={
          <Alert
            type="warning"
            showIcon
            title={
              c.captured_qty
                ? t('campaigns.cancelAfterCapture')
                : t('campaigns.cancelBeforeCapture')
            }
          />
        }
        okText={t('campaigns.actions.cancel')}
        onCancel={() => setModal(null)}
        onSubmit={(reason) => reasonAction('cancel', reason)}
      />
    </>
  );
}

function AttentionBanner({ campaign: c }: { campaign: AdminCampaign }) {
  const { t } = useTranslation();
  if (c.closing_step === 'awaiting_supplier') {
    return (
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        title={t('campaigns.attention.awaitingSupplier')}
        description={
          c.supplier_decision_due_at && (
            <>
              {t('campaigns.supplierDecision.dueBy')}{' '}
              <DateTimeText value={c.supplier_decision_due_at} />
            </>
          )
        }
      />
    );
  }
  if (c.closing_step === 'needs_admin') {
    return (
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        title={t('campaigns.attention.needsAdmin')}
      />
    );
  }
  if (c.status === 'pending_approval') {
    return (
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        title={t('campaigns.attention.pendingApproval')}
      />
    );
  }
  if (c.status === 'failed' || c.status === 'cancelled') {
    return c.end_reason ? (
      <Alert type="error" showIcon style={{ marginBottom: 16 }} title={c.end_reason} />
    ) : null;
  }
  return null;
}

function Overview({ campaign: c }: { campaign: AdminCampaign }) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const closing = c.closing_step !== null || c.closed_at !== null;
  const stepIndex = c.closing_step
    ? CLOSING_STEPS.indexOf(c.closing_step === 'needs_admin' ? 'capturing' : c.closing_step)
    : -1;

  return (
    <Space orientation="vertical" size={16} style={{ width: '100%' }}>
      <Card>
        <Row gutter={[24, 16]} align="middle">
          <Col xs={24} lg={10}>
            <CampaignProgress campaign={c} />
          </Col>
          <Col xs={12} sm={6} lg={3}>
            <Statistic title={t('campaigns.committed')} value={c.committed_qty} />
          </Col>
          <Col xs={12} sm={6} lg={3}>
            <Statistic title={t('campaigns.reserved')} value={c.reserved_qty} />
          </Col>
          <Col xs={12} sm={6} lg={4}>
            <Statistic title={t('campaigns.minTarget')} value={c.min_target_qty} />
          </Col>
          <Col xs={12} sm={6} lg={4}>
            <Statistic title={t('campaigns.maxQty')} value={c.max_qty} />
          </Col>
        </Row>
        {c.deal_confirmed_at && (
          <Tag color="green" style={{ marginTop: 12 }}>
            {t('campaigns.dealConfirmed')} · <DateTimeText value={c.deal_confirmed_at} />
          </Tag>
        )}
      </Card>

      {closing && (
        <Card title={t('campaigns.closing.title')}>
          {c.closing_step && (
            <Steps
              size="small"
              current={stepIndex}
              status={
                c.closing_step === 'needs_admin' || c.closing_step === 'awaiting_supplier'
                  ? 'error'
                  : undefined
              }
              items={CLOSING_STEPS.map((step) => ({ title: t(`status.closingStep.${step}`) }))}
              style={{ marginBottom: 16 }}
            />
          )}
          <Fields
            items={[
              {
                label: t('campaigns.closing.closeReason'),
                value: c.close_reason ? t(`campaigns.closeReasons.${c.close_reason}`) : '—',
              },
              {
                label: t('campaigns.closing.closedAt'),
                value: <DateTimeText value={c.closed_at} />,
              },
              { label: t('campaigns.closing.finalQty'), value: c.final_qty ?? '—' },
              {
                label: t('campaigns.closing.finalUnitPrice'),
                value: <MoneyText value={c.final_unit_price} strong />,
              },
              {
                label: t('campaigns.closing.captureBy'),
                value: <DateTimeText value={c.capture_by} />,
              },
              {
                label: t('campaigns.closing.supplierDecisionDue'),
                value: <DateTimeText value={c.supplier_decision_due_at} />,
              },
              { label: t('campaigns.closing.capturedQty'), value: c.captured_qty ?? '—' },
              { label: t('campaigns.closing.endReason'), value: c.end_reason || '—' },
            ]}
          />
        </Card>
      )}

      <Card title={t('campaigns.terms')}>
        {c.min_target_qty < c.supplier.moq && (
          <Alert
            type="warning"
            showIcon
            style={{ marginBottom: 12 }}
            title={t('campaigns.targetBelowMoq', { target: c.min_target_qty, moq: c.supplier.moq })}
          />
        )}
        <Fields
          items={[
            { label: t('fields.titleAr'), value: c.title_ar },
            { label: t('fields.titleEn'), value: c.title_en },
            {
              label: t('catalog.product'),
              value: <Link to={`/products/${c.product.id}`}>{pick(c.product, 'name')}</Link>,
            },
            {
              label: t('suppliers.supplier'),
              value: (
                <Link to={`/suppliers/${c.supplier.id}`}>
                  <Ltr>{c.supplier.name}</Ltr>
                </Link>
              ),
            },
            { label: t('suppliers.moq'), value: c.supplier.moq },
            {
              label: t('campaigns.quotation'),
              value: <Link to={`/quotations/${c.supplier_quotation_id}`}>{t('common.open')}</Link>,
            },
            {
              label: t('suppliers.landedCostPerUnit'),
              value: <MoneyText value={c.landed_cost_per_unit} />,
            },
            {
              label: t('campaigns.perCustomerMax'),
              value: c.per_customer_max_qty ?? t('common.noLimit'),
            },
            {
              label: t('campaigns.variants'),
              span: 2,
              value: (
                <Space wrap size={4}>
                  {c.variants.map((v) => (
                    <Tag key={v.id}>
                      {pick(v, 'name')} · <Ltr>{v.sku}</Ltr>
                    </Tag>
                  ))}
                </Space>
              ),
            },
            { label: t('campaigns.startsAt'), value: <DateTimeText value={c.starts_at} /> },
            { label: t('campaigns.deadline'), value: <DateTimeText value={c.deadline_at} /> },
            {
              label: t('campaigns.originalDeadline'),
              value: <DateTimeText value={c.original_deadline_at} />,
              hidden: !c.original_deadline_at,
            },
            {
              label: t('campaigns.extendedAt'),
              value: (
                <>
                  <DateTimeText value={c.extended_at} />
                  {c.extension_reason ? ` · ${c.extension_reason}` : ''}
                </>
              ),
              hidden: !c.extended_at,
            },
            {
              label: t('campaigns.deliveryMode'),
              value: t(`campaigns.deliveryModes.${c.delivery_mode}`),
            },
            {
              label: t('campaigns.tiers.title'),
              value: c.tier_version
                ? t('campaigns.tiers.version', { version: c.tier_version })
                : '—',
            },
            {
              label: t('common.createdBy'),
              value: <ActorText actor={c.created_by} />,
            },
            {
              label: t('campaigns.approvedBy'),
              value: (
                <>
                  <ActorText actor={c.approved_by} />
                  {c.approved_at && (
                    <>
                      {' · '}
                      <DateTimeText value={c.approved_at} />
                    </>
                  )}
                </>
              ),
            },
          ]}
        />
      </Card>
    </Space>
  );
}

function EditCampaignModal({
  campaign,
  onClose,
  onDone,
}: {
  campaign: AdminCampaign;
  onClose: () => void;
  onDone: () => Promise<void>;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const [form] = Form.useForm<CampaignFormValues>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const save = async () => {
    const values = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.PATCH('/admin/campaigns/{campaign_id}', {
        params: { path: { campaign_id: campaign.id } },
        body: { ...campaignInputOf(values), lock_version: campaign.lock_version },
      });
      await onDone();
      message.success(t('common.saved'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  const stale = toApiError(error).code === 'stale_campaign';

  return (
    <Modal
      open
      width={860}
      title={t('campaigns.editDraft')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void save()}
      onCancel={onClose}
      destroyOnHidden
    >
      {stale ? (
        <Alert
          type="warning"
          showIcon
          title={t('campaigns.staleCampaign')}
          style={{ marginBottom: 12 }}
        />
      ) : (
        <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      )}
      <Form<CampaignFormValues>
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={campaignFormValues(campaign)}
      >
        <CampaignForm form={form} />
      </Form>
    </Modal>
  );
}
