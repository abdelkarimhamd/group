import { PlusOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Card, Form, Input, InputNumber, Modal, Select, Table } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import { useCategories } from '../../api/lookups';
import type { AdminCategory, AdminCategoryInput } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { Ltr } from '../../components/format';
import { applyFieldErrors, requiredRule } from '../../components/forms';
import { PageHeader } from '../../components/PageHeader';
import { useBilingual } from '../../i18n/LocaleProvider';

export function CategoriesPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const pick = useBilingual();
  const categories = useCategories();
  const [editing, setEditing] = useState<AdminCategory | 'new' | null>(null);
  const rows = categories.data?.data ?? [];
  const nameOf = (id: string | null | undefined) => {
    const found = rows.find((c) => c.id === id);
    return found ? pick(found, 'name') : '—';
  };

  return (
    <>
      <PageHeader
        title={t('nav.categories')}
        extra={
          <Can permission="catalog.edit">
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditing('new')}>
              {t('catalog.newCategory')}
            </Button>
          </Can>
        }
      />
      <Card>
        {categories.isError && <ApiErrorAlert error={categories.error} />}
        <Table<AdminCategory>
          rowKey="id"
          size="middle"
          loading={categories.isPending}
          dataSource={[...rows].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))}
          pagination={false}
          scroll={{ x: 'max-content' }}
          onRow={(record) =>
            can('catalog.edit')
              ? { onClick: () => setEditing(record), style: { cursor: 'pointer' } }
              : {}
          }
          columns={[
            { title: t('fields.nameAr'), dataIndex: 'name_ar' },
            { title: t('fields.nameEn'), dataIndex: 'name_en' },
            { title: t('fields.slug'), dataIndex: 'slug', render: (v: string) => <Ltr>{v}</Ltr> },
            {
              title: t('catalog.parent'),
              dataIndex: 'parent_id',
              render: (v: string | null) => nameOf(v),
            },
            { title: t('fields.sortOrder'), dataIndex: 'sort_order', width: 100 },
          ]}
        />
      </Card>
      {editing && (
        <CategoryModal
          category={editing === 'new' ? null : editing}
          categories={rows}
          onClose={() => setEditing(null)}
        />
      )}
    </>
  );
}

function CategoryModal({
  category,
  categories,
  onClose,
}: {
  category: AdminCategory | null;
  categories: AdminCategory[];
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [form] = Form.useForm<AdminCategoryInput>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const values = await form.validateFields();
    const body: AdminCategoryInput = {
      slug: values.slug.trim(),
      name_ar: values.name_ar.trim(),
      name_en: values.name_en.trim(),
      parent_id: values.parent_id ?? null,
      sort_order: values.sort_order ?? 0,
    };
    setBusy(true);
    setError(null);
    try {
      if (category) {
        await api.PATCH('/admin/categories/{category_id}', {
          params: { path: { category_id: category.id } },
          body,
        });
      } else {
        await api.POST('/admin/categories', { body });
      }
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/categories'] });
      message.success(t('common.saved'));
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
      title={category ? t('catalog.editCategory') : t('catalog.newCategory')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form
        form={form}
        layout="vertical"
        initialValues={category ?? { sort_order: 0 }}
        requiredMark="optional"
      >
        <Form.Item
          name="name_ar"
          label={t('fields.nameAr')}
          rules={[requiredRule(t), { max: 100 }]}
        >
          <Input dir="rtl" />
        </Form.Item>
        <Form.Item
          name="name_en"
          label={t('fields.nameEn')}
          rules={[requiredRule(t), { max: 100 }]}
        >
          <Input dir="ltr" />
        </Form.Item>
        <Form.Item
          name="slug"
          label={t('fields.slug')}
          extra={t('catalog.slugHelp')}
          rules={[
            requiredRule(t),
            { pattern: /^[a-z0-9]+(-[a-z0-9]+)*$/, message: t('validation.slug') },
          ]}
        >
          <Input dir="ltr" />
        </Form.Item>
        <Form.Item name="parent_id" label={t('catalog.parent')}>
          <Select
            allowClear
            options={categories
              .filter((c) => c.id !== category?.id)
              .map((c) => ({ value: c.id, label: pick(c, 'name') }))}
          />
        </Form.Item>
        <Form.Item name="sort_order" label={t('fields.sortOrder')}>
          <InputNumber min={0} precision={0} style={{ width: '100%' }} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
