import { CheckOutlined, EditOutlined, PlusOutlined, StopOutlined } from '@ant-design/icons';
import { Button, Card, Rate, Space, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { $api, api } from '../../api/client';
import { unwrap, useCursorList } from '../../api/lists';
import type { SupplierStatus } from '../../api/types';
import { Can } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { Fields } from '../../components/Fields';
import { ActorText, DateTimeText, Ltr } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { QuotationFormModal } from './QuotationFormModal';
import { quotationColumns } from './QuotationsPage';
import { SupplierFormModal } from './SupplierFormModal';
import { SupplierReviewModal } from './SupplierReviewModal';

export function SupplierDetailPage() {
  const { supplierId = '' } = useParams();
  const { t } = useTranslation();
  const navigate = useNavigate();
  const supplier = $api.useQuery('get', '/admin/suppliers/{supplier_id}', {
    params: { path: { supplier_id: supplierId } },
  });
  const quotationQuery = { supplier_id: supplierId };
  const quotations = useCursorList(['get', '/admin/quotations', quotationQuery], (cursor, signal) =>
    api
      .GET('/admin/quotations', { params: { query: { ...quotationQuery, cursor } }, signal })
      .then(unwrap),
  );
  const [editing, setEditing] = useState(false);
  const [newQuotation, setNewQuotation] = useState(false);
  const [reviewing, setReviewing] = useState<SupplierStatus | null>(null);

  const s = supplier.data?.data;
  if (!s) return <QueryState isPending={supplier.isPending} error={supplier.error} />;

  return (
    <>
      <PageHeader
        backTo="/suppliers"
        title={<Ltr>{s.name}</Ltr>}
        tags={<StatusTag kind="supplier" value={s.status} />}
        extra={
          <>
            <Can permission="suppliers.approve">
              {s.status !== 'approved' && (
                <Button
                  type="primary"
                  icon={<CheckOutlined />}
                  onClick={() => setReviewing('approved')}
                >
                  {t('suppliers.approve')}
                </Button>
              )}
              {s.status !== 'blocked' && (
                <Button danger icon={<StopOutlined />} onClick={() => setReviewing('blocked')}>
                  {t('suppliers.block')}
                </Button>
              )}
              {s.status !== 'prospect' && (
                <Button onClick={() => setReviewing('prospect')}>
                  {t('suppliers.backToProspect')}
                </Button>
              )}
            </Can>
            <Can permission="suppliers.edit">
              <Button icon={<EditOutlined />} onClick={() => setEditing(true)}>
                {t('common.edit')}
              </Button>
            </Can>
          </>
        }
      />
      <Space orientation="vertical" size={16} style={{ width: '100%' }}>
        <Card>
          <Fields
            items={[
              {
                label: t('suppliers.storeUrl'),
                span: 2,
                value: (
                  <Typography.Link
                    href={s.alibaba_store_url}
                    target="_blank"
                    rel="noreferrer noopener"
                  >
                    <Ltr>{s.alibaba_store_url}</Ltr>
                  </Typography.Link>
                ),
              },
              { label: t('suppliers.country'), value: s.country },
              {
                label: t('suppliers.qualityRating'),
                value: s.quality_rating ? <Rate disabled value={s.quality_rating} /> : '—',
              },
              { label: t('suppliers.contactName'), value: s.contact_name || '—' },
              { label: t('suppliers.contactChannel'), value: s.contact_channel || '—' },
              {
                label: t('suppliers.reviewNotes'),
                span: 2,
                value: (
                  <Typography.Paragraph style={{ whiteSpace: 'pre-wrap', margin: 0 }}>
                    {s.review_notes || '—'}
                  </Typography.Paragraph>
                ),
              },
              { label: t('suppliers.reviewedBy'), value: <ActorText actor={s.reviewed_by} /> },
              { label: t('suppliers.reviewedAt'), value: <DateTimeText value={s.reviewed_at} /> },
            ]}
          />
          {s.status !== 'approved' && (
            <Typography.Text type="secondary">{t('suppliers.onlyApprovedHint')}</Typography.Text>
          )}
        </Card>
        <Card
          title={t('nav.quotations')}
          extra={
            <Can permission="suppliers.edit">
              <Button size="small" icon={<PlusOutlined />} onClick={() => setNewQuotation(true)}>
                {t('suppliers.newQuotation')}
              </Button>
            </Can>
          }
        >
          <CursorTable
            list={quotations}
            onRowClick={(q) => navigate(`/quotations/${q.id}`)}
            columns={quotationColumns(t, { showSupplier: false })}
          />
        </Card>
      </Space>
      {editing && <SupplierFormModal supplier={s} onClose={() => setEditing(false)} />}
      <SupplierReviewModal supplier={s} status={reviewing} onClose={() => setReviewing(null)} />
      {newQuotation && (
        <QuotationFormModal
          quotation={null}
          defaults={{ supplier_id: s.id }}
          onClose={() => setNewQuotation(false)}
        />
      )}
    </>
  );
}
