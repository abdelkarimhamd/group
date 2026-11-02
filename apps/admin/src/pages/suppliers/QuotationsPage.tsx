import { PlusOutlined } from '@ant-design/icons';
import { Button, Card, Select, Space } from 'antd';
import type { TFunction } from 'i18next';
import type { ColumnsType } from 'antd/es/table';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import { useProductOptions, useSupplierOptions } from '../../api/lookups';
import type { Quotation, QuotationStatus } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { DateText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';
import { QuotationFormModal } from './QuotationFormModal';

export function quotationColumns(
  t: TFunction,
  opts: {
    showSupplier?: boolean;
    supplierName?: (id: string) => string;
    productName?: (id: string) => string;
  } = {},
): ColumnsType<Quotation> {
  const columns: ColumnsType<Quotation> = [];
  if (opts.showSupplier !== false && opts.supplierName) {
    columns.push({
      title: t('suppliers.supplier'),
      key: 'supplier',
      render: (_, q) => <Ltr>{opts.supplierName!(q.supplier_id)}</Ltr>,
    });
  }
  if (opts.productName) {
    columns.push({
      title: t('catalog.product'),
      key: 'product',
      render: (_, q) => opts.productName!(q.product_id),
    });
  }
  columns.push(
    { title: t('suppliers.moq'), dataIndex: 'moq' },
    {
      title: t('suppliers.unitCost'),
      key: 'unit_cost',
      render: (_, q) => <MoneyText value={q.unit_cost} />,
    },
    {
      title: t('suppliers.landedCostPerUnit'),
      key: 'landed',
      render: (_, q) => <MoneyText value={q.landed_cost_per_unit} strong />,
    },
    {
      title: t('suppliers.validUntil'),
      dataIndex: 'valid_until',
      render: (v: string) => <DateText value={v} />,
    },
    {
      title: t('common.status'),
      dataIndex: 'status',
      render: (v: QuotationStatus) => <StatusTag kind="quotation" value={v} />,
    },
  );
  return columns;
}

export function QuotationsPage() {
  const { t } = useTranslation();
  const pick = useBilingual();
  const { can } = useAuth();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const suppliers = useSupplierOptions();
  const products = useProductOptions(can('catalog.view'));
  const [creating, setCreating] = useState(params.get('new') === '1' && can('suppliers.edit'));

  const query = cleanQuery({
    supplier_id: params.get('supplier_id') ?? undefined,
    product_id: params.get('product_id') ?? undefined,
    status: (params.get('status') as QuotationStatus | null) ?? undefined,
  });
  const list = useCursorList(['get', '/admin/quotations', query], (cursor, signal) =>
    api.GET('/admin/quotations', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );

  const setFilter = (key: string, value: string | undefined) => {
    const next = new URLSearchParams(params);
    next.delete('new');
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  const supplierName = (id: string) => suppliers.data?.data.find((s) => s.id === id)?.name ?? '—';
  const productName = (id: string) => {
    const p = products.data?.data.find((x) => x.id === id);
    return p ? pick(p, 'name') : '—';
  };

  return (
    <>
      <PageHeader
        title={t('nav.quotations')}
        subtitle={t('suppliers.quotationsSubtitle')}
        extra={
          <Can permission="suppliers.edit">
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreating(true)}>
              {t('suppliers.newQuotation')}
            </Button>
          </Can>
        }
      />
      <Card>
        <Space wrap style={{ marginBottom: 16 }}>
          <Select
            allowClear
            showSearch
            optionFilterProp="label"
            placeholder={t('suppliers.supplier')}
            value={query.supplier_id}
            onChange={(v?: string) => setFilter('supplier_id', v)}
            options={(suppliers.data?.data ?? []).map((s) => ({ value: s.id, label: s.name }))}
            style={{ width: 220 }}
          />
          <Select
            allowClear
            showSearch
            optionFilterProp="label"
            placeholder={t('catalog.product')}
            value={query.product_id}
            onChange={(v?: string) => setFilter('product_id', v)}
            options={(products.data?.data ?? []).map((p) => ({
              value: p.id,
              label: pick(p, 'name'),
            }))}
            style={{ width: 220 }}
          />
          <Select
            allowClear
            placeholder={t('common.status')}
            value={query.status}
            onChange={(v?: string) => setFilter('status', v)}
            options={statusOptions(t, 'quotation')}
            style={{ width: 160 }}
          />
        </Space>
        <CursorTable<Quotation>
          list={list}
          onRowClick={(q) => navigate(`/quotations/${q.id}`)}
          columns={quotationColumns(t, { supplierName, productName })}
        />
      </Card>
      {creating && (
        <QuotationFormModal
          quotation={null}
          defaults={cleanQuery({ supplier_id: query.supplier_id, product_id: query.product_id })}
          onClose={() => {
            setCreating(false);
            if (params.has('new')) setFilter('new', undefined);
          }}
        />
      )}
    </>
  );
}
