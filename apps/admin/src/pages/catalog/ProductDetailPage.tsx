import { DeleteOutlined, EditOutlined, PlusOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import {
  App,
  Button,
  Card,
  Col,
  Empty,
  Image,
  Popconfirm,
  Row,
  Select,
  Space,
  Table,
  Typography,
  Upload,
} from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { $api, api, formDataSerializer } from '../../api/client';
import { useCategories } from '../../api/lookups';
import type { AdminImage, AdminVariant, Quotation } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { ApiErrorAlert, apiErrorMessage } from '../../components/ApiErrorAlert';
import { Fields } from '../../components/Fields';
import { DateText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';
import { ProductFormModal } from './ProductFormModal';
import { VariantFormModal } from './VariantFormModal';
import { MAX_IMAGE_BYTES } from '../../lib/uploads';

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export function ProductDetailPage() {
  const { productId = '' } = useParams();
  const { t } = useTranslation();
  const pick = useBilingual();
  const { can } = useAuth();
  const categories = useCategories();
  const product = $api.useQuery('get', '/admin/products/{product_id}', {
    params: { path: { product_id: productId } },
  });
  const quotations = $api.useQuery(
    'get',
    '/admin/quotations',
    { params: { query: { product_id: productId, per_page: 50 } } },
    { enabled: can('suppliers.view') },
  );
  const [editing, setEditing] = useState(false);
  const [variant, setVariant] = useState<AdminVariant | 'new' | null>(null);

  const p = product.data?.data;
  if (!p) return <QueryState isPending={product.isPending} error={product.error} />;
  const category = categories.data?.data.find((c) => c.id === p.category_id);

  return (
    <>
      <PageHeader
        backTo="/products"
        title={pick(p, 'name')}
        tags={<StatusTag kind="product" value={p.status} />}
        extra={
          <Can permission="catalog.edit">
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
              { label: t('fields.nameAr'), value: p.name_ar },
              { label: t('fields.nameEn'), value: p.name_en },
              { label: t('catalog.category'), value: category ? pick(category, 'name') : '—' },
              { label: t('catalog.hsCode'), value: <Ltr>{p.hs_code ?? '—'}</Ltr> },
              {
                label: t('catalog.alibabaUrl'),
                span: 2,
                value: (
                  <Typography.Link href={p.alibaba_url} target="_blank" rel="noreferrer noopener">
                    <Ltr>{p.alibaba_url}</Ltr>
                  </Typography.Link>
                ),
              },
              {
                label: t('fields.descriptionAr'),
                span: 2,
                value: (
                  <Typography.Paragraph style={{ whiteSpace: 'pre-wrap', margin: 0 }}>
                    {p.description_ar || '—'}
                  </Typography.Paragraph>
                ),
              },
              {
                label: t('fields.descriptionEn'),
                span: 2,
                value: (
                  <Typography.Paragraph dir="ltr" style={{ whiteSpace: 'pre-wrap', margin: 0 }}>
                    {p.description_en || '—'}
                  </Typography.Paragraph>
                ),
              },
              { label: t('common.createdAt'), value: <DateTimeText value={p.created_at} /> },
              { label: t('common.updatedAt'), value: <DateTimeText value={p.updated_at} /> },
            ]}
          />
        </Card>

        <Card
          title={t('catalog.variants')}
          extra={
            <Can permission="catalog.edit">
              <Button size="small" icon={<PlusOutlined />} onClick={() => setVariant('new')}>
                {t('catalog.addVariant')}
              </Button>
            </Can>
          }
        >
          <Table<AdminVariant>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={p.variants}
            scroll={{ x: 'max-content' }}
            columns={[
              { title: t('catalog.sku'), dataIndex: 'sku', render: (v: string) => <Ltr>{v}</Ltr> },
              { title: t('fields.name'), key: 'name', render: (_, v) => pick(v, 'name') },
              {
                title: t('catalog.optionValues'),
                key: 'options',
                render: (_, v) =>
                  Object.entries(v.option_values ?? {})
                    .map(([k, val]) => `${k}: ${val}`)
                    .join(', ') || '—',
              },
              {
                title: t('catalog.dimensions.weight_grams'),
                dataIndex: 'weight_grams',
                render: (v: number | null | undefined) => v ?? '—',
              },
              {
                title: t('common.status'),
                dataIndex: 'status',
                render: (v: string | undefined) => (
                  <StatusTag kind="variant" value={v ?? 'active'} />
                ),
              },
              ...(can('catalog.edit')
                ? [
                    {
                      key: 'edit',
                      width: 60,
                      render: (_: unknown, v: AdminVariant) => (
                        <Button
                          type="text"
                          size="small"
                          icon={<EditOutlined />}
                          aria-label={t('common.edit')}
                          onClick={() => setVariant(v)}
                        />
                      ),
                    },
                  ]
                : []),
            ]}
          />
        </Card>

        <ProductImages productId={p.id} images={p.images} variants={p.variants} />

        {can('suppliers.view') && (
          <Card
            title={t('nav.quotations')}
            extra={
              <Can permission="suppliers.edit">
                <Link to={`/quotations?new=1&product_id=${p.id}`}>
                  <Button size="small" icon={<PlusOutlined />}>
                    {t('suppliers.newQuotation')}
                  </Button>
                </Link>
              </Can>
            }
          >
            {quotations.isError && <ApiErrorAlert error={quotations.error} />}
            <Table<Quotation>
              rowKey="id"
              size="small"
              loading={quotations.isPending}
              pagination={false}
              dataSource={quotations.data?.data ?? []}
              scroll={{ x: 'max-content' }}
              columns={[
                {
                  title: t('suppliers.quotation'),
                  key: 'id',
                  render: (_, q) => <Link to={`/quotations/${q.id}`}>{t('common.open')}</Link>,
                },
                { title: t('suppliers.moq'), dataIndex: 'moq' },
                {
                  title: t('suppliers.unitCost'),
                  key: 'cost',
                  render: (_, q) => <MoneyText value={q.unit_cost} />,
                },
                {
                  title: t('suppliers.landedCostPerUnit'),
                  key: 'landed',
                  render: (_, q) => <MoneyText value={q.landed_cost_per_unit} />,
                },
                {
                  title: t('suppliers.validUntil'),
                  dataIndex: 'valid_until',
                  render: (v: string) => <DateText value={v} />,
                },
                {
                  title: t('common.status'),
                  dataIndex: 'status',
                  render: (v: string) => <StatusTag kind="quotation" value={v} />,
                },
              ]}
            />
          </Card>
        )}
      </Space>

      {editing && <ProductFormModal product={p} onClose={() => setEditing(false)} />}
      {variant && (
        <VariantFormModal
          productId={p.id}
          variant={variant === 'new' ? null : variant}
          onClose={() => setVariant(null)}
        />
      )}
    </>
  );
}

function ProductImages({
  productId,
  images,
  variants,
}: {
  productId: string;
  images: AdminImage[];
  variants: AdminVariant[];
}) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const { message } = App.useApp();
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const [variantId, setVariantId] = useState<string | undefined>();
  const [uploading, setUploading] = useState(false);
  const sorted = [...images].sort((a, b) => a.sort_order - b.sort_order);

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ['get', '/admin/products/{product_id}'] });

  const upload = async (file: File) => {
    if (!IMAGE_TYPES.includes(file.type)) {
      message.error(t('catalog.imageType'));
      return;
    }
    if (file.size > MAX_IMAGE_BYTES) {
      message.error(t('catalog.imageTooLarge'));
      return;
    }
    setUploading(true);
    try {
      await api.POST('/admin/products/{product_id}/images', {
        params: { path: { product_id: productId } },
        body: {
          file: file as unknown as string,
          product_variant_id: variantId ?? null,
          sort_order: (sorted.at(-1)?.sort_order ?? -1) + 1,
        },
        bodySerializer: (body) => formDataSerializer(body as Record<string, unknown>),
      });
      await refresh();
      message.success(t('catalog.imageUploaded'));
    } catch (e) {
      message.error(apiErrorMessage(t, e));
    } finally {
      setUploading(false);
    }
  };

  const remove = async (image: AdminImage) => {
    try {
      await api.DELETE('/admin/product-images/{image_id}', {
        params: { path: { image_id: image.id } },
      });
      await refresh();
    } catch (e) {
      message.error(apiErrorMessage(t, e));
    }
  };

  return (
    <Card
      title={t('catalog.photos')}
      extra={
        <Can permission="catalog.edit">
          <Space wrap>
            <Select
              size="small"
              allowClear
              placeholder={t('catalog.photoForVariant')}
              value={variantId}
              onChange={setVariantId}
              style={{ minWidth: 180 }}
              options={variants.map((v) => ({ value: v.id, label: pick(v, 'name') }))}
            />
            <Upload
              accept={IMAGE_TYPES.join(',')}
              showUploadList={false}
              beforeUpload={(file) => {
                void upload(file);
                return false;
              }}
            >
              <Button size="small" icon={<UploadOutlined />} loading={uploading}>
                {t('catalog.uploadPhoto')}
              </Button>
            </Upload>
          </Space>
        </Can>
      }
    >
      {sorted.length === 0 ? (
        <Empty description={t('catalog.noPhotos')} />
      ) : (
        <Row gutter={[12, 12]}>
          {sorted.map((image) => {
            const v = variants.find((x) => x.id === image.product_variant_id);
            return (
              <Col key={image.id} xs={12} sm={8} md={6} lg={4}>
                <Card
                  size="small"
                  cover={
                    <Image
                      src={image.url}
                      alt={pick(image, 'alt') || ''}
                      height={120}
                      style={{ objectFit: 'cover' }}
                    />
                  }
                  actions={
                    can('catalog.edit')
                      ? [
                          <Popconfirm
                            key="delete"
                            title={t('catalog.deletePhoto')}
                            okButtonProps={{ danger: true }}
                            onConfirm={() => remove(image)}
                          >
                            <DeleteOutlined aria-label={t('common.delete')} />
                          </Popconfirm>,
                        ]
                      : undefined
                  }
                >
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    {v ? pick(v, 'name') : t('catalog.allVariants')}
                  </Typography.Text>
                </Card>
              </Col>
            );
          })}
        </Row>
      )}
    </Card>
  );
}
