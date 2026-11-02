import { PlusOutlined } from '@ant-design/icons';
import { Button, Card, Rate, Select, Space } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { Supplier, SupplierStatus } from '../../api/types';
import { Can } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { DateTimeText, Ltr } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';
import { SupplierFormModal } from './SupplierFormModal';

export function SuppliersPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const [creating, setCreating] = useState(false);
  const query = cleanQuery({
    status: (params.get('status') as SupplierStatus | null) ?? undefined,
  });
  const list = useCursorList(['get', '/admin/suppliers', query], (cursor, signal) =>
    api.GET('/admin/suppliers', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );

  return (
    <>
      <PageHeader
        title={t('nav.suppliers')}
        extra={
          <Can permission="suppliers.edit">
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreating(true)}>
              {t('suppliers.newSupplier')}
            </Button>
          </Can>
        }
      />
      <Card>
        <Space wrap style={{ marginBottom: 16 }}>
          <Select
            allowClear
            placeholder={t('common.status')}
            value={query.status}
            onChange={(value?: string) => {
              const next = new URLSearchParams(params);
              if (value) next.set('status', value);
              else next.delete('status');
              setParams(next, { replace: true });
            }}
            options={statusOptions(t, 'supplier')}
            style={{ width: 180 }}
          />
        </Space>
        <CursorTable<Supplier>
          list={list}
          onRowClick={(s) => navigate(`/suppliers/${s.id}`)}
          columns={[
            { title: t('fields.name'), dataIndex: 'name', render: (v: string) => <Ltr>{v}</Ltr> },
            { title: t('suppliers.country'), dataIndex: 'country' },
            {
              title: t('suppliers.qualityRating'),
              dataIndex: 'quality_rating',
              render: (v: number | null) =>
                v ? <Rate disabled value={v} style={{ fontSize: 12 }} /> : '—',
            },
            {
              title: t('common.status'),
              dataIndex: 'status',
              render: (v: SupplierStatus) => <StatusTag kind="supplier" value={v} />,
            },
            {
              title: t('suppliers.reviewedAt'),
              dataIndex: 'reviewed_at',
              render: (v: string | null) => <DateTimeText value={v} />,
            },
          ]}
        />
      </Card>
      {creating && <SupplierFormModal supplier={null} onClose={() => setCreating(false)} />}
    </>
  );
}
