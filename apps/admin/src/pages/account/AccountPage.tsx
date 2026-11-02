import { Button, Card, Space, Tag, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../auth/AuthProvider';
import { Fields } from '../../components/Fields';
import { Ltr } from '../../components/format';
import { formatSaudiMobile } from '../../lib/phone';
import { PageHeader } from '../../components/PageHeader';
import { ChangePasswordCard } from './ChangePasswordCard';

export function AccountPage() {
  const { t } = useTranslation();
  const { admin, signOut } = useAuth();
  const navigate = useNavigate();
  if (!admin) return null;
  return (
    <>
      <PageHeader
        title={t('nav.account')}
        extra={
          <Button danger onClick={() => void signOut().then(() => navigate('/login'))}>
            {t('auth.signOut')}
          </Button>
        }
      />
      <Space orientation="vertical" size={16} style={{ width: '100%' }}>
        <Card>
          <Fields
            column={1}
            items={[
              { label: t('fields.name'), value: admin.name },
              { label: t('auth.email'), value: <Ltr>{admin.email}</Ltr> },
              { label: t('admins.phone'), value: <Ltr>{formatSaudiMobile(admin.phone)}</Ltr> },
              {
                label: t('account.roles'),
                value: (
                  <Space wrap size={4}>
                    {admin.roles.map((r) => (
                      <Tag key={r}>{t(`roles.${r}`)}</Tag>
                    ))}
                  </Space>
                ),
              },
              {
                label: t('account.permissions'),
                value: (
                  <Space wrap size={4}>
                    {admin.permissions.map((p) => (
                      <Tooltip key={p} title={<Ltr>{p}</Ltr>}>
                        <Tag variant="filled">{t(`permissions.${p}`)}</Tag>
                      </Tooltip>
                    ))}
                  </Space>
                ),
              },
            ]}
          />
          <Typography.Paragraph type="secondary" style={{ marginTop: 16, marginBottom: 0 }}>
            {t('account.permissionsHelp')}
          </Typography.Paragraph>
        </Card>
        <ChangePasswordCard />
      </Space>
    </>
  );
}
