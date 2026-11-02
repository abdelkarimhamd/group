import {
  AuditOutlined,
  BankOutlined,
  CheckSquareOutlined,
  CreditCardOutlined,
  DashboardOutlined,
  FileTextOutlined,
  FlagOutlined,
  LogoutOutlined,
  ShopOutlined,
  ShoppingCartOutlined,
  ShoppingOutlined,
  TagsOutlined,
  TeamOutlined,
  TruckOutlined,
  UndoOutlined,
  UserOutlined,
} from '@ant-design/icons';
import {
  Avatar,
  Button,
  Dropdown,
  Grid,
  Layout,
  Menu,
  Segmented,
  Space,
  Tag,
  Typography,
  theme,
} from 'antd';
import type { ItemType } from 'antd/es/menu/interface';
import { Suspense, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { Loading } from '../components/Results';
import type { Permission } from '../auth/permissions';
import { useLocale } from '../i18n/LocaleProvider';

type NavItem = { key: string; label: string; icon: React.ReactNode; permission?: Permission };
type NavGroup = { key: string; label: string; items: NavItem[] };

export function AppLayout() {
  const { t } = useTranslation();
  const { admin, can, signOut } = useAuth();
  const { locale, setLocale } = useLocale();
  const location = useLocation();
  const navigate = useNavigate();
  const screens = Grid.useBreakpoint();
  const [collapsed, setCollapsed] = useState(false);
  const { token } = theme.useToken();

  const groups: NavGroup[] = useMemo(
    () => [
      {
        key: 'overview',
        label: t('nav.overview'),
        items: [{ key: '/', label: t('nav.home'), icon: <DashboardOutlined /> }],
      },
      {
        key: 'sourcing',
        label: t('nav.sourcing'),
        items: [
          {
            key: '/products',
            label: t('nav.products'),
            icon: <ShoppingOutlined />,
            permission: 'catalog.view',
          },
          {
            key: '/categories',
            label: t('nav.categories'),
            icon: <TagsOutlined />,
            permission: 'catalog.view',
          },
          {
            key: '/suppliers',
            label: t('nav.suppliers'),
            icon: <ShopOutlined />,
            permission: 'suppliers.view',
          },
          {
            key: '/quotations',
            label: t('nav.quotations'),
            icon: <FileTextOutlined />,
            permission: 'suppliers.view',
          },
        ],
      },
      {
        key: 'selling',
        label: t('nav.selling'),
        items: [
          {
            key: '/campaigns',
            label: t('nav.campaigns'),
            icon: <FlagOutlined />,
            permission: 'campaigns.view',
          },
          {
            key: '/orders',
            label: t('nav.orders'),
            icon: <ShoppingCartOutlined />,
            permission: 'orders.view',
          },
          {
            key: '/purchase-orders',
            label: t('nav.purchaseOrders'),
            icon: <TruckOutlined />,
            permission: 'purchasing.view',
          },
        ],
      },
      {
        key: 'money',
        label: t('nav.money'),
        items: [
          {
            key: '/payments',
            label: t('nav.payments'),
            icon: <CreditCardOutlined />,
            permission: 'payments.view',
          },
          {
            key: '/refunds',
            label: t('nav.refunds'),
            icon: <UndoOutlined />,
            permission: 'payments.view',
          },
          {
            key: '/approvals',
            label: t('nav.approvals'),
            icon: <CheckSquareOutlined />,
            permission: 'approvals.view',
          },
          {
            key: '/reconciliation',
            label: t('nav.reconciliation'),
            icon: <BankOutlined />,
            permission: 'reconciliation.view',
          },
        ],
      },
      {
        key: 'admin',
        label: t('nav.administration'),
        items: [
          {
            key: '/admins',
            label: t('nav.admins'),
            icon: <TeamOutlined />,
            permission: 'admins.manage',
          },
          {
            key: '/audit-log',
            label: t('nav.auditLog'),
            icon: <AuditOutlined />,
            permission: 'audit.view',
          },
        ],
      },
    ],
    [t],
  );

  const menuItems: ItemType[] = groups
    .map((group) => ({
      type: 'group' as const,
      key: group.key,
      label: collapsed ? null : group.label,
      children: group.items
        .filter((item) => !item.permission || can(item.permission))
        .map((item) => ({
          key: item.key,
          icon: item.icon,
          label: <Link to={item.key}>{item.label}</Link>,
        })),
    }))
    .filter((group) => group.children.length > 0);

  const selectedKey =
    groups
      .flatMap((g) => g.items.map((i) => i.key))
      .filter((key) =>
        key === '/' ? location.pathname === '/' : location.pathname.startsWith(key),
      )
      .sort((a, b) => b.length - a.length)[0] ?? '/';

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Layout.Sider
        theme="light"
        width={232}
        collapsible
        collapsed={collapsed}
        onCollapse={setCollapsed}
        breakpoint="lg"
        collapsedWidth={screens.md ? 64 : 0}
        // Stays in view while the page scrolls; the menu scrolls on its own when it is long.
        style={{
          borderInlineEnd: `1px solid ${token.colorBorderSecondary}`,
          position: 'sticky',
          top: 0,
          height: '100vh',
        }}
      >
        <div style={{ height: '100%', overflowY: 'auto' }}>
          <div style={{ padding: '16px 20px', whiteSpace: 'nowrap', overflow: 'hidden' }}>
            <Typography.Text strong style={{ fontSize: 16 }}>
              {collapsed ? t('app.shortName') : t('app.name')}
            </Typography.Text>
          </div>
          <Menu
            mode="inline"
            selectedKeys={[selectedKey]}
            items={menuItems}
            style={{ borderInlineEnd: 0 }}
          />
        </div>
      </Layout.Sider>
      <Layout>
        <Layout.Header
          style={{
            background: token.colorBgContainer,
            borderBottom: `1px solid ${token.colorBorderSecondary}`,
            paddingInline: 16,
            display: 'flex',
            justifyContent: 'flex-end',
            alignItems: 'center',
            gap: 12,
          }}
        >
          <Segmented
            size="small"
            value={locale}
            onChange={(value) => setLocale(value as 'ar' | 'en')}
            options={[
              { value: 'ar', label: 'العربية' },
              { value: 'en', label: 'English' },
            ]}
          />
          <Dropdown
            trigger={['click']}
            menu={{
              items: [
                { key: 'account', icon: <UserOutlined />, label: t('nav.account') },
                { type: 'divider' },
                { key: 'logout', icon: <LogoutOutlined />, label: t('auth.signOut'), danger: true },
              ],
              onClick: ({ key }) => {
                if (key === 'account') navigate('/account');
                if (key === 'logout') void signOut().then(() => navigate('/login'));
              },
            }}
          >
            <Button type="text" style={{ height: 'auto', paddingBlock: 4 }}>
              <Space>
                <Avatar size="small" icon={<UserOutlined />} />
                <span>{admin?.name}</span>
                {screens.md && admin?.roles[0] && (
                  <Tag variant="filled">{t(`roles.${admin.roles[0]}`)}</Tag>
                )}
              </Space>
            </Button>
          </Dropdown>
        </Layout.Header>
        <Layout.Content style={{ padding: screens.md ? 24 : 12, minWidth: 0 }}>
          <Suspense fallback={<Loading />}>
            <Outlet />
          </Suspense>
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
