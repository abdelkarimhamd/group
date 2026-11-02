import { ArrowLeftOutlined, ArrowRightOutlined } from '@ant-design/icons';
import { Button, Flex, Space, Typography } from 'antd';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useLocale } from '../i18n/LocaleProvider';

export function PageHeader({
  title,
  subtitle,
  tags,
  extra,
  backTo,
}: {
  title: ReactNode;
  subtitle?: ReactNode;
  tags?: ReactNode;
  extra?: ReactNode;
  backTo?: string;
}) {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const { locale } = useLocale();
  return (
    <Flex justify="space-between" align="flex-start" wrap gap={12} style={{ marginBottom: 16 }}>
      <Space align="start" size={12}>
        {backTo && (
          <Button
            type="text"
            aria-label={t('common.back')}
            icon={locale === 'ar' ? <ArrowRightOutlined /> : <ArrowLeftOutlined />}
            onClick={() => navigate(backTo)}
          />
        )}
        <div>
          <Space wrap size={8} align="center">
            <Typography.Title level={3} style={{ margin: 0 }}>
              {title}
            </Typography.Title>
            {tags}
          </Space>
          {subtitle && (
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4 }}>
              {subtitle}
            </Typography.Text>
          )}
        </div>
      </Space>
      {extra && (
        <Space wrap size={8}>
          {extra}
        </Space>
      )}
    </Flex>
  );
}
