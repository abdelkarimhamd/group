import { lazy, type ComponentType } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { RequireAuth, RequirePermission } from './auth/guards';
import type { Permission } from './auth/permissions';
import { NotFoundResult } from './components/Results';
import { AppLayout } from './layout/AppLayout';
import { LoginPage } from './pages/auth/LoginPage';

/** Each screen loads on first visit, so signing in does not wait for every screen's code. */
function page<K extends string>(load: () => Promise<Record<K, ComponentType>>, name: K) {
  return lazy(() => load().then((module) => ({ default: module[name] })));
}

const AccountPage = page(() => import('./pages/account/AccountPage'), 'AccountPage');
const AdminsPage = page(() => import('./pages/admins/AdminsPage'), 'AdminsPage');
const ApprovalsPage = page(() => import('./pages/approvals/ApprovalsPage'), 'ApprovalsPage');
const AuditLogPage = page(() => import('./pages/audit/AuditLogPage'), 'AuditLogPage');
const CampaignCreatePage = page(
  () => import('./pages/campaigns/CampaignCreatePage'),
  'CampaignCreatePage',
);
const CampaignDetailPage = page(
  () => import('./pages/campaigns/CampaignDetailPage'),
  'CampaignDetailPage',
);
const CampaignsPage = page(() => import('./pages/campaigns/CampaignsPage'), 'CampaignsPage');
const CategoriesPage = page(() => import('./pages/catalog/CategoriesPage'), 'CategoriesPage');
const ProductDetailPage = page(
  () => import('./pages/catalog/ProductDetailPage'),
  'ProductDetailPage',
);
const ProductsPage = page(() => import('./pages/catalog/ProductsPage'), 'ProductsPage');
const DashboardPage = page(() => import('./pages/dashboard/DashboardPage'), 'DashboardPage');
const OrderDetailPage = page(() => import('./pages/orders/OrderDetailPage'), 'OrderDetailPage');
const OrdersPage = page(() => import('./pages/orders/OrdersPage'), 'OrdersPage');
const ReturnsPage = page(() => import('./pages/orders/ReturnsPage'), 'ReturnsPage');
const PaymentDetailPage = page(
  () => import('./pages/payments/PaymentDetailPage'),
  'PaymentDetailPage',
);
const PaymentsPage = page(() => import('./pages/payments/PaymentsPage'), 'PaymentsPage');
const RefundsPage = page(() => import('./pages/payments/RefundsPage'), 'RefundsPage');
const PurchaseOrderDetailPage = page(
  () => import('./pages/purchasing/PurchaseOrderDetailPage'),
  'PurchaseOrderDetailPage',
);
const PurchaseOrdersPage = page(
  () => import('./pages/purchasing/PurchaseOrdersPage'),
  'PurchaseOrdersPage',
);
const LedgerPage = page(() => import('./pages/ledger/LedgerPage'), 'LedgerPage');
const SettlementsPage = page(() => import('./pages/ledger/SettlementsPage'), 'SettlementsPage');
const ReconciliationPage = page(
  () => import('./pages/reconciliation/ReconciliationPage'),
  'ReconciliationPage',
);
const ReconciliationRunPage = page(
  () => import('./pages/reconciliation/ReconciliationRunPage'),
  'ReconciliationRunPage',
);
const QuotationDetailPage = page(
  () => import('./pages/suppliers/QuotationDetailPage'),
  'QuotationDetailPage',
);
const QuotationsPage = page(() => import('./pages/suppliers/QuotationsPage'), 'QuotationsPage');
const SupplierDetailPage = page(
  () => import('./pages/suppliers/SupplierDetailPage'),
  'SupplierDetailPage',
);
const SuppliersPage = page(() => import('./pages/suppliers/SuppliersPage'), 'SuppliersPage');

function guard(permission: Permission, element: React.ReactNode) {
  return <RequirePermission permission={permission}>{element}</RequirePermission>;
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route
        element={
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        }
      >
        <Route index element={<DashboardPage />} />
        <Route path="account" element={<AccountPage />} />

        <Route path="products" element={guard('catalog.view', <ProductsPage />)} />
        <Route path="products/:productId" element={guard('catalog.view', <ProductDetailPage />)} />
        <Route path="categories" element={guard('catalog.view', <CategoriesPage />)} />

        <Route path="suppliers" element={guard('suppliers.view', <SuppliersPage />)} />
        <Route
          path="suppliers/:supplierId"
          element={guard('suppliers.view', <SupplierDetailPage />)}
        />
        <Route path="quotations" element={guard('suppliers.view', <QuotationsPage />)} />
        <Route
          path="quotations/:quotationId"
          element={guard('suppliers.view', <QuotationDetailPage />)}
        />

        <Route path="campaigns" element={guard('campaigns.view', <CampaignsPage />)} />
        <Route path="campaigns/new" element={guard('campaigns.edit', <CampaignCreatePage />)} />
        <Route
          path="campaigns/:campaignId"
          element={guard('campaigns.view', <CampaignDetailPage />)}
        />

        <Route path="payments" element={guard('payments.view', <PaymentsPage />)} />
        <Route path="payments/:paymentId" element={guard('payments.view', <PaymentDetailPage />)} />
        <Route path="refunds" element={guard('payments.view', <RefundsPage />)} />
        <Route path="approvals" element={guard('approvals.view', <ApprovalsPage />)} />

        <Route path="orders" element={guard('orders.view', <OrdersPage />)} />
        <Route path="orders/:orderId" element={guard('orders.view', <OrderDetailPage />)} />
        <Route path="returns" element={guard('orders.view', <ReturnsPage />)} />

        <Route path="purchase-orders" element={guard('purchasing.view', <PurchaseOrdersPage />)} />
        <Route
          path="purchase-orders/:purchaseOrderId"
          element={guard('purchasing.view', <PurchaseOrderDetailPage />)}
        />

        <Route
          path="reconciliation"
          element={guard('reconciliation.view', <ReconciliationPage />)}
        />
        <Route
          path="reconciliation/:runId"
          element={guard('reconciliation.view', <ReconciliationRunPage />)}
        />
        <Route path="ledger" element={guard('ledger.view', <LedgerPage />)} />
        <Route path="settlements" element={guard('ledger.view', <SettlementsPage />)} />
        <Route path="admins" element={guard('admins.manage', <AdminsPage />)} />
        <Route path="audit-log" element={guard('audit.view', <AuditLogPage />)} />

        <Route path="home" element={<Navigate to="/" replace />} />
        <Route path="*" element={<NotFoundResult />} />
      </Route>
    </Routes>
  );
}
