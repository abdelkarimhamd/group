import {
  Alert,
  Checkbox,
  Col,
  Form,
  Input,
  InputNumber,
  Row,
  Select,
  type FormInstance,
} from 'antd';
import type { Dayjs } from 'dayjs';
import { useTranslation } from 'react-i18next';
import { $api } from '../../api/client';
import { useProductOptions, useSupplierOptions } from '../../api/lookups';
import type { AdminCampaign, AdminCampaignInput } from '../../api/types';
import { intRules, requiredRule, RiyadhDateTimePicker } from '../../components/forms';
import { CAMPAIGN_DURATION_DAYS } from '../../config';
import { useBilingual } from '../../i18n/LocaleProvider';
import { formatMoney } from '../../lib/money';
import { riyadhPickerValueToUtc, toRiyadhPickerValue } from '../../lib/time';

export type CampaignFormValues = Omit<AdminCampaignInput, 'starts_at'> & { starts_at: Dayjs };

export function campaignFormValues(c: AdminCampaign): CampaignFormValues {
  return {
    product_id: c.product.id,
    supplier_quotation_id: c.supplier_quotation_id,
    title_ar: c.title_ar,
    title_en: c.title_en,
    product_variant_ids: c.variants.map((v) => v.id),
    min_target_qty: c.min_target_qty,
    max_qty: c.max_qty,
    per_customer_max_qty: c.per_customer_max_qty,
    starts_at: toRiyadhPickerValue(c.starts_at)!,
    delivery_mode: 'warehouse',
  };
}

export function campaignInputOf(v: CampaignFormValues): AdminCampaignInput {
  return {
    product_id: v.product_id,
    supplier_quotation_id: v.supplier_quotation_id,
    title_ar: v.title_ar.trim(),
    title_en: v.title_en.trim(),
    product_variant_ids: v.product_variant_ids,
    min_target_qty: v.min_target_qty,
    max_qty: v.max_qty,
    per_customer_max_qty: v.per_customer_max_qty ?? null,
    starts_at: riyadhPickerValueToUtc(v.starts_at),
    // Direct shipping comes after the pilot.
    delivery_mode: 'warehouse',
  };
}

/** Fields shared by "New campaign" and "Edit draft". */
export function CampaignForm({ form }: { form: FormInstance<CampaignFormValues> }) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const products = useProductOptions();
  const suppliers = useSupplierOptions();
  const productId = Form.useWatch('product_id', form);
  const quotationId = Form.useWatch('supplier_quotation_id', form);
  const minTarget = Form.useWatch('min_target_qty', form);
  const quotations = $api.useQuery(
    'get',
    '/admin/quotations',
    { params: { query: { product_id: productId ?? '', per_page: 100 } } },
    { enabled: Boolean(productId) },
  );
  const product = products.data?.data.find((p) => p.id === productId);
  const quotation = quotations.data?.data.find((q) => q.id === quotationId);
  const supplierName = (id: string) => suppliers.data?.data.find((s) => s.id === id)?.name ?? '—';

  return (
    <Row gutter={12}>
      <Col xs={24} md={12}>
        <Form.Item name="product_id" label={t('catalog.product')} rules={[requiredRule(t)]}>
          <Select
            showSearch
            optionFilterProp="label"
            loading={products.isPending}
            onChange={(id: string) => {
              const next = products.data?.data.find((p) => p.id === id);
              form.setFieldsValue({
                supplier_quotation_id: undefined,
                product_variant_ids: (next?.variants ?? [])
                  .filter((v) => v.status !== 'archived')
                  .map((v) => v.id),
                title_ar: form.getFieldValue('title_ar') || next?.name_ar,
                title_en: form.getFieldValue('title_en') || next?.name_en,
              });
            }}
            options={(products.data?.data ?? [])
              .filter((p) => p.status !== 'archived')
              .map((p) => ({ value: p.id, label: pick(p, 'name') }))}
          />
        </Form.Item>
      </Col>
      <Col xs={24} md={12}>
        <Form.Item
          name="supplier_quotation_id"
          label={t('campaigns.quotation')}
          extra={t('campaigns.quotationHelp')}
          rules={[requiredRule(t)]}
        >
          <Select
            disabled={!productId}
            loading={quotations.isFetching}
            options={(quotations.data?.data ?? []).map((q) => ({
              value: q.id,
              disabled: q.status === 'rejected' || q.status === 'expired',
              label: `${supplierName(q.supplier_id)} · ${t('suppliers.moq')} ${q.moq} · ${formatMoney(
                q.landed_cost_per_unit,
              )} · ${t(`status.quotation.${q.status}`)}`,
            }))}
          />
        </Form.Item>
      </Col>
      <Col span={24}>
        <Form.Item
          name="product_variant_ids"
          label={t('campaigns.variants')}
          extra={t('campaigns.variantsHelp')}
          rules={[
            { required: true, type: 'array', min: 1, message: t('campaigns.variantRequired') },
          ]}
        >
          <Checkbox.Group
            options={(product?.variants ?? []).map((v) => ({
              value: v.id,
              label: pick(v, 'name'),
              disabled: v.status === 'archived',
            }))}
          />
        </Form.Item>
      </Col>
      <Col xs={24} md={12}>
        <Form.Item
          name="title_ar"
          label={t('fields.titleAr')}
          rules={[requiredRule(t), { max: 200 }]}
        >
          <Input dir="rtl" />
        </Form.Item>
      </Col>
      <Col xs={24} md={12}>
        <Form.Item
          name="title_en"
          label={t('fields.titleEn')}
          rules={[requiredRule(t), { max: 200 }]}
        >
          <Input dir="ltr" />
        </Form.Item>
      </Col>
      <Col xs={12} md={6}>
        <Form.Item
          name="min_target_qty"
          label={t('campaigns.minTarget')}
          rules={intRules(t, { min: 1 })}
        >
          <InputNumber min={1} precision={0} style={{ width: '100%' }} />
        </Form.Item>
      </Col>
      <Col xs={12} md={6}>
        <Form.Item
          name="max_qty"
          label={t('campaigns.maxQty')}
          dependencies={['min_target_qty']}
          rules={[
            ...intRules(t, { min: 1 }),
            ({ getFieldValue }) => ({
              validator: async (_, value: number | undefined) => {
                const min = getFieldValue('min_target_qty') as number | undefined;
                if (value !== undefined && min !== undefined && value < min) {
                  throw new Error(t('campaigns.maxBelowTarget'));
                }
              },
            }),
          ]}
        >
          <InputNumber min={1} precision={0} style={{ width: '100%' }} />
        </Form.Item>
      </Col>
      <Col xs={12} md={6}>
        <Form.Item
          name="per_customer_max_qty"
          label={t('campaigns.perCustomerMax')}
          dependencies={['max_qty']}
          rules={[
            ({ getFieldValue }) => ({
              validator: async (_, value: number | null | undefined) => {
                const max = getFieldValue('max_qty') as number | undefined;
                if (value && max && value > max)
                  throw new Error(t('campaigns.perCustomerAboveMax'));
              },
            }),
          ]}
        >
          <InputNumber
            min={1}
            precision={0}
            style={{ width: '100%' }}
            placeholder={t('common.noLimit')}
          />
        </Form.Item>
      </Col>
      <Col xs={12} md={6}>
        <Form.Item
          name="starts_at"
          label={t('campaigns.startsAt')}
          extra={t('campaigns.startsAtHelp', { days: CAMPAIGN_DURATION_DAYS })}
          rules={[{ required: true, message: t('validation.required') }]}
        >
          <RiyadhDateTimePicker />
        </Form.Item>
      </Col>
      {quotation && minTarget !== undefined && minTarget !== null && minTarget < quotation.moq && (
        <Col span={24}>
          <Alert
            type="warning"
            showIcon
            title={t('campaigns.warnings.min_target_below_supplier_moq')}
            description={t('campaigns.targetBelowMoq', { target: minTarget, moq: quotation.moq })}
          />
        </Col>
      )}
    </Row>
  );
}
