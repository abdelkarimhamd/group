import { useQueryClient } from '@tanstack/react-query';
import {
  Alert,
  App,
  Col,
  DatePicker,
  Divider,
  Form,
  Input,
  InputNumber,
  Modal,
  Row,
  Select,
  Typography,
} from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api/client';
import { toApiError } from '../../api/errors';
import { useProductOptions, useSupplierOptions } from '../../api/lookups';
import type { Quotation, QuotationInput } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { Ltr } from '../../components/format';
import {
  applyFieldErrors,
  intRules,
  minorOf,
  MoneyInput,
  moneyRules,
  rateRules,
  requiredRule,
} from '../../components/forms';
import { useBilingual } from '../../i18n/LocaleProvider';
import { formatMinor, minorToMajorText, parseMajorToMinor, sumMinor } from '../../lib/money';
import { dayjs, pickerValueToDate } from '../../lib/time';

export const BREAKDOWN_FIELDS = [
  'product_halalas',
  'freight_halalas',
  'customs_duty_halalas',
  'import_vat_halalas',
  'clearance_halalas',
  'last_mile_halalas',
] as const;
type BreakdownField = (typeof BREAKDOWN_FIELDS)[number];

type FormValues = Omit<
  QuotationInput,
  'unit_cost_minor' | 'valid_until' | 'landed_cost_breakdown'
> & {
  unit_cost_minor: string;
  valid_until: Dayjs;
  landed_cost_breakdown: Record<BreakdownField, string>;
  status?: 'draft' | 'accepted' | 'rejected';
};

function initialValues(q: Quotation): Partial<FormValues> {
  const b = q.landed_cost_breakdown;
  return {
    supplier_id: q.supplier_id,
    product_id: q.product_id,
    product_variant_id: q.product_variant_id,
    moq: q.moq,
    unit_cost_minor: minorToMajorText(q.unit_cost.amount_minor),
    currency: q.unit_cost.currency as QuotationInput['currency'],
    shipping_terms: q.shipping_terms,
    lead_time_days: q.lead_time_days,
    valid_until: dayjs(q.valid_until),
    fx_rate_to_sar: q.fx_rate_to_sar,
    landed_cost_basis_qty: q.landed_cost_basis_qty,
    landed_cost_breakdown: {
      product_halalas: minorToMajorText(b.product.amount_minor),
      freight_halalas: minorToMajorText(b.freight.amount_minor),
      customs_duty_halalas: minorToMajorText(b.customs_duty.amount_minor),
      import_vat_halalas: minorToMajorText(b.import_vat.amount_minor),
      clearance_halalas: minorToMajorText(b.clearance.amount_minor),
      last_mile_halalas: minorToMajorText(b.last_mile.amount_minor),
    },
    status: q.status === 'expired' ? undefined : q.status,
  };
}

/** Records a supplier quote and its landed cost per unit, or edits one still in use only by drafts. */
export function QuotationFormModal({
  quotation,
  defaults,
  onClose,
}: {
  quotation: Quotation | null;
  defaults?: Partial<Pick<QuotationInput, 'supplier_id' | 'product_id'>>;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const { message } = App.useApp();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const suppliers = useSupplierOptions();
  const products = useProductOptions();
  const [form] = Form.useForm<FormValues>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const productId = Form.useWatch('product_id', form);
  const currency = Form.useWatch('currency', form) ?? 'USD';
  const breakdown = Form.useWatch('landed_cost_breakdown', form);
  const product = products.data?.data.find((p) => p.id === productId);

  const landedPreview = (() => {
    if (!breakdown) return null;
    const parts = BREAKDOWN_FIELDS.map((f) => parseMajorToMinor(breakdown[f] ?? ''));
    if (parts.some((p) => p === null)) return null;
    return sumMinor(parts as number[]);
  })();

  const submit = async () => {
    const v = await form.validateFields();
    const body: QuotationInput = {
      supplier_id: v.supplier_id,
      product_id: v.product_id,
      product_variant_id: v.product_variant_id ?? null,
      moq: v.moq,
      unit_cost_minor: minorOf(v.unit_cost_minor),
      currency: v.currency,
      shipping_terms: v.shipping_terms.trim(),
      lead_time_days: v.lead_time_days,
      valid_until: pickerValueToDate(v.valid_until),
      fx_rate_to_sar: v.fx_rate_to_sar.trim(),
      landed_cost_basis_qty: v.landed_cost_basis_qty,
      landed_cost_breakdown: Object.fromEntries(
        BREAKDOWN_FIELDS.map((f) => [f, minorOf(v.landed_cost_breakdown[f])]),
      ) as QuotationInput['landed_cost_breakdown'],
    };
    setBusy(true);
    setError(null);
    try {
      if (quotation) {
        await api.PATCH('/admin/quotations/{quotation_id}', {
          params: { path: { quotation_id: quotation.id } },
          body: { ...body, status: v.status },
        });
        await queryClient.invalidateQueries({
          queryKey: ['get', '/admin/quotations/{quotation_id}'],
        });
      } else {
        const { data } = await api.POST('/admin/quotations', { body });
        navigate(`/quotations/${data!.data.id}`);
      }
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/quotations'] });
      message.success(t('common.saved'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  const frozen = toApiError(error).code === 'quotation_frozen';

  return (
    <Modal
      open
      width={820}
      title={quotation ? t('suppliers.editQuotation') : t('suppliers.newQuotation')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      {frozen ? (
        <Alert
          type="warning"
          showIcon
          title={t('suppliers.quotationFrozen')}
          style={{ marginBottom: 12 }}
        />
      ) : (
        <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      )}
      <Form<FormValues>
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={
          quotation
            ? initialValues(quotation)
            : { currency: 'USD', shipping_terms: 'FOB', fx_rate_to_sar: '3.75', ...defaults }
        }
      >
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item name="supplier_id" label={t('suppliers.supplier')} rules={[requiredRule(t)]}>
              <Select
                showSearch
                optionFilterProp="label"
                loading={suppliers.isPending}
                options={(suppliers.data?.data ?? []).map((s) => ({
                  value: s.id,
                  label: s.name,
                  disabled: s.status === 'blocked',
                }))}
              />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="product_id" label={t('catalog.product')} rules={[requiredRule(t)]}>
              <Select
                showSearch
                optionFilterProp="label"
                loading={products.isPending}
                onChange={() => form.setFieldValue('product_variant_id', null)}
                options={(products.data?.data ?? []).map((p) => ({
                  value: p.id,
                  label: pick(p, 'name'),
                }))}
              />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="product_variant_id"
              label={t('suppliers.variant')}
              extra={t('suppliers.variantHelp')}
            >
              <Select
                allowClear
                disabled={!product}
                options={(product?.variants ?? []).map((v) => ({
                  value: v.id,
                  label: pick(v, 'name'),
                }))}
              />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="moq" label={t('suppliers.moq')} rules={intRules(t, { min: 1 })}>
              <InputNumber min={1} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item
              name="lead_time_days"
              label={t('suppliers.leadTimeDays')}
              rules={intRules(t, { min: 0 })}
            >
              <InputNumber min={0} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="currency" label={t('suppliers.currency')} rules={[requiredRule(t)]}>
              <Select options={['USD', 'CNY', 'SAR'].map((c) => ({ value: c, label: c }))} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item
              name="unit_cost_minor"
              label={t('suppliers.unitCost')}
              rules={moneyRules(t, { min: 1 })}
            >
              <MoneyInput currency={currency} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item
              name="fx_rate_to_sar"
              label={t('suppliers.fxRate')}
              extra={t('suppliers.fxRateHelp', { currency })}
              rules={rateRules(t)}
            >
              <Input dir="ltr" inputMode="decimal" />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item
              name="shipping_terms"
              label={t('suppliers.shippingTerms')}
              rules={[requiredRule(t), { max: 20 }]}
            >
              <Input dir="ltr" placeholder="FOB" />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item
              name="valid_until"
              label={t('suppliers.validUntil')}
              rules={[{ required: true, message: t('validation.required') }]}
            >
              <DatePicker style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          {quotation && (
            <Col xs={12} md={6}>
              <Form.Item name="status" label={t('common.status')}>
                <Select
                  options={(['draft', 'accepted', 'rejected'] as const).map((s) => ({
                    value: s,
                    label: t(`status.quotation.${s}`),
                  }))}
                />
              </Form.Item>
            </Col>
          )}
        </Row>

        <Divider titlePlacement="start">{t('suppliers.landedCost')}</Divider>
        <Typography.Paragraph type="secondary">
          {t('suppliers.landedCostHelp')}
        </Typography.Paragraph>
        <Row gutter={12}>
          <Col xs={12} md={6}>
            <Form.Item
              name="landed_cost_basis_qty"
              label={t('suppliers.basisQty')}
              rules={intRules(t, { min: 1 })}
            >
              <InputNumber min={1} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          {BREAKDOWN_FIELDS.map((field) => (
            <Col xs={12} md={6} key={field}>
              <Form.Item
                name={['landed_cost_breakdown', field]}
                label={t(`suppliers.breakdown.${field}`)}
                rules={moneyRules(t)}
              >
                <MoneyInput />
              </Form.Item>
            </Col>
          ))}
        </Row>
        <Typography.Text strong>
          {t('suppliers.landedCostPerUnit')}:{' '}
          <Ltr>{landedPreview === null ? '—' : formatMinor(landedPreview, 'SAR')}</Ltr>
        </Typography.Text>
      </Form>
    </Modal>
  );
}
