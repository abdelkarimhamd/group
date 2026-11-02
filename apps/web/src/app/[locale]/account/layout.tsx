import { Suspense } from "react";
import { AccountNav } from "@/components/account-nav";
import { Container } from "@/components/ui";

export default function AccountLayout({ children }: LayoutProps<"/[locale]/account">) {
  return (
    <Container className="py-8">
      <Suspense fallback={<div className="mb-6 h-10" />}>
        <AccountNav />
      </Suspense>
      {children}
    </Container>
  );
}
