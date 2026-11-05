import { EditOutlined, PaperClipOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Card, Space, Table, Typography, Upload } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { $api, api, formDataSerializer } from '../../api/client';
import { toApiError } from '../../api/errors';
import { useProductOptions, useSupplierOptions } from '../../api/lookups';
import type { Money } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { apiErrorMessage } from '../../components/ApiErrorAlert';
import { Fields } from '../../components/Fields';
import { DateText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';
import { QuotationFormModal } from './QuotationFormModal';
import { MAX_ATTACHMENT_BYTES } from '../../lib/uploads';

const ATTACHMENT_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
const BREAKDOWN_KEYS = [
  'product',
  'freight',
  'customs_duty',
  'import_vat',
  'clearance',
  'last_mile',
] as const;

export function QuotationDetailPage() {
  const { quotationId = '' } = useParams();
  const { t } = useTranslation();
  const pick = useBilingual();
  const { can } = useAuth();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const suppliers = useSupplierOptions();
  const products = useProductOptions(can('catalog.view'));
  const quotation = $api.useQuery('get', '/admin/quotations/{quotation_id}', {
    params: { path: { quotation_id: quotationId } },
  });
  const [editing, setEditing] = useState(false);
  const [uploading, setUploading] = useState(false);

  const q = quotation.data?.data;
  if (!q) return <QueryState isPending={quotation.isPending} error={quotation.error} />;

  const supplier = suppliers.data?.data.find((s) => s.id === q.supplier_id);
  const product = products.data?.data.find((p) => p.id === q.product_id);
  const variant = product?.variants.find((v) => v.id === q.product_variant_id);

  const upload = async (file: File) => {
    if (!ATTACHMENT_TYPES.includes(file.type))
      return void message.error(t('suppliers.attachmentType'));
    if (file.size > MAX_ATTACHMENT_BYTES)
      return void message.error(t('suppliers.attachmentTooLarge'));
    setUploading(true);
    try {
      await api.POST('/admin/quotations/{quotation_id}/attachment', {
        params: { path: { quotation_id: q.id } },
        body: { file: file as unknown as string },
        bodySerializer: (body) => formDataSerializer(body as Record<string, unknown>),
      });
      await queryClient.invalidateQueries({
        queryKey: ['get', '/admin/quotations/{quotation_id}'],
      });
      message.success(t('suppliers.attachmentUploaded'));
    } catch (e) {
      message.error(
        toApiError(e).code === 'quotation_frozen'
          ? t('suppliers.quotationFrozen')
          : apiErrorMessage(t, e),
      );
    } finally {
      setUploading(false);
    }
  };

  const breakdownRows = BREAKDOWN_KEYS.map((key) => ({
    key,
    label: t(`suppliers.breakdown.${key}_halalas`),
    amount: q.landed_cost_breakdown[key] as Money,
  }));

  return (
    <>
      <PageHeader
        backTo="/quotations"
        title={t('suppliers.quotation')}
        subtitle={
          <>
            <Ltr>{supplier?.name ?? ''}</Ltr>
            {product ? ` · ${pick(product, 'name')}` : ''}
          </>
        }
        tags={<StatusTag kind="quotation" value={q.status} />}
        extra={
          <Can permission="suppliers.edit">
            <Upload
              accept={ATTACHMENT_TYPES.join(',')}
              showUploadList={false}
              beforeUpload={(file) => {
                void upload(file);
                return false;
              }}
            >
              <Button icon={<UploadOutlined />} loading={uploading}>
                {q.attachment_url ? t('suppliers.replaceAttachment') : t('suppliers.attachQuote')}
              </Button>
            </Upload>
            <Button icon={<EditOutlined />} onClick={() => setEditing(true)}>
              {t('common.edit')}
            </Button>
          </Can>
        }
      />
      <Space orientation="vertical" size={16} style={{ width: '100%' }}>
        <Card>
          <Fields
            items={[
              {
                label: t('suppliers.supplier'),
                value: supplier ? (
                  <Link to={`/suppliers/${supplier.id}`}>
                    <Ltr>{supplier.name}</Ltr>
                  </Link>
                ) : (
                  '—'
                ),
              },
              {
                label: t('catalog.product'),
                value: product ? (
                  <Link to={`/products/${product.id}`}>{pick(product, 'name')}</Link>
                ) : (
                  '—'
                ),
              },
              {
                label: t('suppliers.variant'),
                value: variant ? pick(variant, 'name') : t('catalog.allVariants'),
              },
              { label: t('suppliers.moq'), value: q.moq },
              { label: t('suppliers.unitCost'), value: <MoneyText value={q.unit_cost} /> },
              { label: t('suppliers.fxRate'), value: <Ltr>{q.fx_rate_to_sar}</Ltr> },
              { label: t('suppliers.shippingTerms'), value: <Ltr>{q.shipping_terms}</Ltr> },
              { label: t('suppliers.leadTimeDays'), value: q.lead_time_days },
              { label: t('suppliers.validUntil'), value: <DateText value={q.valid_until} /> },
              {
                label: t('suppliers.attachment'),
                value: q.attachment_url ? (
                  <Typography.Link
                    href={q.attachment_url}
                    target="_blank"
                    rel="noreferrer noopener"
                  >
                    <PaperClipOutlined /> {t('common.open')}
                  </Typography.Link>
                ) : (
                  '—'
                ),
              },
              {
                label: t('suppliers.usedByCampaigns'),
                span: 2,
                value:
                  q.used_by_campaign_ids.length === 0 ? (
                    '—'
                  ) : (
                    <Space wrap>
                      {q.used_by_campaign_ids.map((id, i) => (
                        <Link key={id} to={`/campaigns/${id}`}>
                          {t('campaigns.campaign')} {i + 1}
                        </Link>
                      ))}
                    </Space>
                  ),
              },
            ]}
          />
        </Card>
        <Card title={t('suppliers.landedCost')}>
          <Typography.Paragraph type="secondary">
            {t('suppliers.basisQty')}: {q.landed_cost_basis_qty}
          </Typography.Paragraph>
          <Table
            size="small"
            pagination={false}
            dataSource={breakdownRows}
            columns={[
              { title: t('suppliers.costItem'), dataIndex: 'label' },
              {
                title: t('suppliers.perUnit'),
                dataIndex: 'amount',
                render: (m: Money) => <MoneyText value={m} />,
                align: 'end',
              },
            ]}
            summary={() => (
              <Table.Summary.Row>
                <Table.Summary.Cell index={0}>
                  <Typography.Text strong>{t('suppliers.landedCostPerUnit')}</Typography.Text>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={1} align="end">
                  <MoneyText value={q.landed_cost_per_unit} strong />
                </Table.Summary.Cell>
              </Table.Summary.Row>
            )}
          />
        </Card>
      </Space>
      {editing && <QuotationFormModal quotation={q} onClose={() => setEditing(false)} />}
    </>
  );
}
