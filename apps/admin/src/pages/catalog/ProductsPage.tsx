import { PlusOutlined } from '@ant-design/icons';
import { Button, Card, Input, Select, Space } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import { useCategories } from '../../api/lookups';
import type { AdminProduct, ProductStatus } from '../../api/types';
import { Can } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { DateTimeText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';
import { ProductFormModal } from './ProductFormModal';

export function ProductsPage() {
  const { t } = useTranslation();
  const pick = useBilingual();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const [creating, setCreating] = useState(false);
  const categories = useCategories();

  const query = cleanQuery({
    status: (params.get('status') as ProductStatus | null) ?? undefined,
    q: params.get('q') ?? undefined,
  });
  const list = useCursorList(['get', '/admin/products', query], (cursor, signal) =>
    api.GET('/admin/products', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );

  const setFilter = (key: string, value: string | undefined) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  const categoryName = (id: string) => {
    const c = categories.data?.data.find((x) => x.id === id);
    return c ? pick(c, 'name') : '—';
  };

  return (
    <>
      <PageHeader
        title={t('nav.products')}
        extra={
          <Can permission="catalog.edit">
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreating(true)}>
              {t('catalog.newProduct')}
            </Button>
          </Can>
        }
      />
      <Card>
        <Space wrap style={{ marginBottom: 16 }}>
          <Input.Search
            allowClear
            placeholder={t('catalog.searchProducts')}
            defaultValue={query.q}
            onSearch={(value) => setFilter('q', value.trim() || undefined)}
            style={{ width: 280 }}
          />
          <Select
            allowClear
            placeholder={t('common.status')}
            value={query.status}
            onChange={(value) => setFilter('status', value)}
            options={statusOptions(t, 'product')}
            style={{ width: 160 }}
          />
        </Space>
        <CursorTable<AdminProduct>
          list={list}
          onRowClick={(p) => navigate(`/products/${p.id}`)}
          columns={[
            { title: t('fields.name'), key: 'name', render: (_, p) => pick(p, 'name') },
            {
              title: t('catalog.category'),
              key: 'category',
              render: (_, p) => categoryName(p.category_id),
            },
            { title: t('catalog.variants'), key: 'variants', render: (_, p) => p.variants.length },
            {
              title: t('common.status'),
              dataIndex: 'status',
              render: (v: ProductStatus) => <StatusTag kind="product" value={v} />,
            },
            {
              title: t('common.updatedAt'),
              dataIndex: 'updated_at',
              render: (v: string) => <DateTimeText value={v} />,
            },
          ]}
        />
      </Card>
      {creating && <ProductFormModal product={null} onClose={() => setCreating(false)} />}
    </>
  );
}
