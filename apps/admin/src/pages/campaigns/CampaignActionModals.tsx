import {
  Alert,
  App,
  Col,
  Form,
  Input,
  InputNumber,
  Modal,
  Radio,
  Row,
  Select,
  Space,
  Typography,
} from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { $api, api } from '../../api/client';
import { toApiError } from '../../api/errors';
import type {
  AdminCampaign,
  ApprovalRequest,
  ExtensionWindow,
  MarginReport,
} from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { DateTimeText } from '../../components/format';
import {
  applyFieldErrors,
  intRules,
  minorOf,
  MoneyInput,
  moneyRules,
  requiredRule,
  RiyadhDateTimePicker,
} from '../../components/forms';
import { Loading } from '../../components/Results';
import { CAMPAIGN_DURATION_DAYS } from '../../config';
import { riyadhPickerValueToUtc, toRiyadhPickerValue } from '../../lib/time';
import { MarginReportView } from './MarginTab';

type ModalProps = { campaign: AdminCampaign; onClose: () => void; onDone: () => Promise<void> };

export function isApprovalRequest(value: unknown): value is ApprovalRequest {
  return (
    typeof value === 'object' &&
    value !== null &&
    'subject_type' in value &&
    'requested_by' in value
  );
}

/** Approve and lock the terms. Shows the margin check first; campaigns.override_margin may override a failing one. */
export function ApproveModal({ campaign, onClose, onDone }: ModalProps) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const { can } = useAuth();
  const margin = $api.useQuery('get', '/admin/campaigns/{campaign_id}/margin', {
    params: { path: { campaign_id: campaign.id } },
  });
  const [report, setReport] = useState<MarginReport | null>(null);
  const [override, setOverride] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const shown = report ?? margin.data?.data ?? null;
  const canOverride = can('campaigns.override_margin');
  const blocked = shown !== null && !shown.passes;

  const approve = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/campaigns/{campaign_id}/approve', {
        params: { path: { campaign_id: campaign.id } },
        body: blocked ? { override_reason: override.trim() } : {},
      });
      await onDone();
      message.success(t('campaigns.approve.done'));
      onClose();
    } catch (e) {
      const apiError = toApiError(e);
      const body = apiError.body as { margin?: MarginReport } | undefined;
      if (apiError.code === 'margin_below_floor' && body?.margin) setReport(body.margin);
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const canSubmit = !blocked || (canOverride && override.trim().length >= 3);

  return (
    <Modal
      open
      width={820}
      title={t('campaigns.approve.title')}
      okText={blocked ? t('campaigns.approve.override') : t('campaigns.approve.confirm')}
      okButtonProps={{ loading: busy, disabled: margin.isPending || !canSubmit, danger: blocked }}
      onOk={() => void approve()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Typography.Paragraph>
        {t('campaigns.approve.explain', { days: CAMPAIGN_DURATION_DAYS })}
      </Typography.Paragraph>
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      {campaign.min_target_qty < campaign.supplier.moq && (
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 12 }}
          title={t('campaigns.targetBelowMoq', {
            target: campaign.min_target_qty,
            moq: campaign.supplier.moq,
          })}
        />
      )}
      {margin.isPending && !report ? (
        <Loading />
      ) : margin.isError && !report ? (
        <ApiErrorAlert error={margin.error} />
      ) : shown ? (
        <MarginReportView report={shown} />
      ) : null}
      {blocked &&
        (canOverride ? (
          <Form layout="vertical" style={{ marginTop: 16 }}>
            <Form.Item
              label={t('campaigns.approve.overrideReason')}
              required
              help={t('campaigns.approve.overrideHelp')}
            >
              <Input.TextArea
                rows={3}
                maxLength={1000}
                value={override}
                onChange={(e) => setOverride(e.target.value)}
              />
            </Form.Item>
          </Form>
        ) : (
          <Alert
            type="error"
            showIcon
            style={{ marginTop: 16 }}
            title={t('campaigns.approve.blockedNoOverride')}
          />
        ))}
    </Modal>
  );
}

const BLOCKED_REASONS = [
  'not_active',
  'already_extended',
  'deal_confirmed',
  'deadline_passed',
  'no_room_before_hold_expiry',
] as const;

/** The one deadline extension, inside the window the API reports. */
export function ExtendModal({ campaign, onClose, onDone }: ModalProps) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const windowQuery = $api.useQuery('get', '/admin/campaigns/{campaign_id}/extension-window', {
    params: { path: { campaign_id: campaign.id } },
  });
  const [form] = Form.useForm<{ new_deadline_at: Dayjs; reason: string }>();
  const [blockedWindow, setBlockedWindow] = useState<ExtensionWindow | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const win = blockedWindow ?? windowQuery.data?.data;
  const latest = toRiyadhPickerValue(win?.latest_allowed_deadline_at);
  const current = toRiyadhPickerValue(campaign.deadline_at);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/campaigns/{campaign_id}/extend', {
        params: { path: { campaign_id: campaign.id } },
        body: {
          new_deadline_at: riyadhPickerValueToUtc(v.new_deadline_at),
          reason: v.reason.trim(),
        },
      });
      await onDone();
      message.success(t('campaigns.extend.done'));
      onClose();
    } catch (e) {
      const apiError = toApiError(e);
      const body = apiError.body as { window?: ExtensionWindow } | undefined;
      if (apiError.code === 'extension_not_allowed' && body?.window) setBlockedWindow(body.window);
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      title={t('campaigns.extend.title')}
      okText={t('campaigns.extend.confirm')}
      okButtonProps={{ loading: busy, disabled: !win?.allowed }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      {windowQuery.isPending ? (
        <Loading />
      ) : (
        <Space orientation="vertical" style={{ width: '100%' }}>
          <ApiErrorAlert error={windowQuery.error ?? error} />
          <Typography.Paragraph style={{ margin: 0 }}>
            {t('campaigns.extend.explain')}
          </Typography.Paragraph>
          {win && (
            <>
              <Typography.Text>
                {t('campaigns.deadline')}: <DateTimeText value={campaign.deadline_at} />
              </Typography.Text>
              <Typography.Text>
                {t('campaigns.extend.earliestHoldExpiry')}:{' '}
                <DateTimeText value={win.earliest_hold_expires_at} />
              </Typography.Text>
              {win.allowed ? (
                <Alert
                  type="info"
                  showIcon
                  title={
                    <>
                      {t('campaigns.extend.latestAllowed')}:{' '}
                      <DateTimeText value={win.latest_allowed_deadline_at} />
                    </>
                  }
                />
              ) : (
                <Alert
                  type="warning"
                  showIcon
                  title={t(
                    `campaigns.extend.blocked.${
                      BLOCKED_REASONS.find((r) => r === win.blocked_reason) ?? 'not_active'
                    }`,
                  )}
                />
              )}
            </>
          )}
          {win?.allowed && (
            <Form form={form} layout="vertical" requiredMark="optional">
              <Form.Item
                name="new_deadline_at"
                label={t('campaigns.extend.newDeadline')}
                rules={[{ required: true, message: t('validation.required') }]}
              >
                <RiyadhDateTimePicker
                  disabledDate={(d) =>
                    (current !== undefined && d.isBefore(current, 'day')) ||
                    (latest !== undefined && d.isAfter(latest, 'day'))
                  }
                />
              </Form.Item>
              <Form.Item
                name="reason"
                label={t('common.reason')}
                rules={[
                  requiredRule(t),
                  { min: 3, message: t('validation.minLength', { n: 3 }) },
                  { max: 1000 },
                ]}
              >
                <Input.TextArea rows={3} />
              </Form.Item>
            </Form>
          )}
        </Space>
      )}
    </Modal>
  );
}

/** Records the supplier's reconfirmation of price and stock, which gates every capture. */
export function SupplierDecisionModal({ campaign, onClose, onDone }: ModalProps) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const [form] = Form.useForm<{
    decision: 'confirmed' | 'rejected';
    unit_cost: string;
    currency: 'USD' | 'CNY' | 'SAR';
    available_qty: number;
    quotation_reference?: string;
    note?: string;
  }>();
  const decision = Form.useWatch('decision', form);
  const currency = Form.useWatch('currency', form) ?? 'USD';
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const needed = campaign.final_qty ?? campaign.committed_qty;

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/campaigns/{campaign_id}/supplier-decision', {
        params: { path: { campaign_id: campaign.id } },
        body:
          v.decision === 'confirmed'
            ? {
                decision: 'confirmed',
                unit_cost_minor: minorOf(v.unit_cost),
                currency: v.currency,
                available_qty: v.available_qty,
                quotation_reference: v.quotation_reference?.trim() || undefined,
                note: v.note?.trim() || null,
              }
            : { decision: 'rejected', note: v.note?.trim() || null },
      });
      await onDone();
      message.success(t('campaigns.supplierDecision.done'));
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
      width={640}
      title={t('campaigns.supplierDecision.title')}
      okText={
        decision === 'rejected'
          ? t('campaigns.supplierDecision.reject')
          : t('campaigns.supplierDecision.confirm')
      }
      okButtonProps={{ loading: busy, danger: decision === 'rejected' }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Typography.Paragraph>
        {t('campaigns.supplierDecision.explain', { qty: needed })}
        {campaign.supplier_decision_due_at && (
          <>
            {' '}
            {t('campaigns.supplierDecision.dueBy')}{' '}
            <DateTimeText value={campaign.supplier_decision_due_at} />.
          </>
        )}
      </Typography.Paragraph>
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={{ decision: 'confirmed', currency: 'USD' }}
      >
        <Form.Item name="decision">
          <Radio.Group
            optionType="button"
            options={[
              { value: 'confirmed', label: t('campaigns.supplierDecision.confirmedOption') },
              { value: 'rejected', label: t('campaigns.supplierDecision.rejectedOption') },
            ]}
          />
        </Form.Item>
        {decision === 'rejected' ? (
          <Alert
            type="error"
            showIcon
            style={{ marginBottom: 12 }}
            title={t('campaigns.supplierDecision.rejectWarning')}
          />
        ) : (
          <Row gutter={12}>
            <Col xs={12} md={8}>
              <Form.Item name="currency" label={t('suppliers.currency')} rules={[requiredRule(t)]}>
                <Select options={['USD', 'CNY', 'SAR'].map((c) => ({ value: c, label: c }))} />
              </Form.Item>
            </Col>
            <Col xs={12} md={8}>
              <Form.Item
                name="unit_cost"
                label={t('suppliers.unitCost')}
                rules={moneyRules(t, { min: 1 })}
              >
                <MoneyInput currency={currency} />
              </Form.Item>
            </Col>
            <Col xs={12} md={8}>
              <Form.Item
                name="available_qty"
                label={t('campaigns.supplierDecision.availableQty')}
                rules={[
                  ...intRules(t, { min: 0 }),
                  {
                    validator: async (_, value: number | undefined) => {
                      if (value !== undefined && value < needed) {
                        throw new Error(t('campaigns.supplierDecision.notEnough', { qty: needed }));
                      }
                    },
                  },
                ]}
              >
                <InputNumber min={0} precision={0} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="quotation_reference"
                label={t('campaigns.supplierDecision.reference')}
                rules={[{ max: 200 }]}
              >
                <Input dir="ltr" />
              </Form.Item>
            </Col>
          </Row>
        )}
        <Form.Item name="note" label={t('common.note')} rules={[{ max: 2000 }]}>
          <Input.TextArea rows={3} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Proceed with fewer captured units than the target or MOQ, or cancel (which needs finance approval). */
export function BelowTargetModal({ campaign, onClose, onDone }: ModalProps) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const [form] = Form.useForm<{ decision: 'proceed' | 'cancel'; reason: string }>();
  const decision = Form.useWatch('decision', form);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.POST('/admin/campaigns/{campaign_id}/below-target-decision', {
        params: { path: { campaign_id: campaign.id } },
        body: { decision: v.decision, reason: v.reason.trim() },
      });
      await onDone();
      message.success(
        isApprovalRequest(data?.data)
          ? t('campaigns.sentForApproval')
          : t('campaigns.belowTarget.done'),
      );
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
      title={t('campaigns.belowTarget.title')}
      okText={t('common.confirm')}
      okButtonProps={{ loading: busy, danger: decision === 'cancel' }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Typography.Paragraph>
        {t('campaigns.belowTarget.explain', {
          captured: campaign.captured_qty ?? 0,
          target: campaign.min_target_qty,
          moq: campaign.supplier.moq,
        })}
      </Typography.Paragraph>
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={{ decision: 'proceed' }}
      >
        <Form.Item name="decision">
          <Radio.Group
            options={[
              { value: 'proceed', label: t('campaigns.belowTarget.proceed') },
              { value: 'cancel', label: t('campaigns.belowTarget.cancel') },
            ]}
          />
        </Form.Item>
        <Typography.Paragraph type="secondary">
          {decision === 'cancel'
            ? t('campaigns.belowTarget.cancelHelp')
            : t('campaigns.belowTarget.proceedHelp')}
        </Typography.Paragraph>
        <Form.Item
          name="reason"
          label={t('common.reason')}
          rules={[
            requiredRule(t),
            { min: 3, message: t('validation.minLength', { n: 3 }) },
            { max: 1000 },
          ]}
        >
          <Input.TextArea rows={3} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
