import type { Metadata } from "next";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { SignInForm } from "@/components/sign-in-form";
import { Card, Container, Skeleton } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/sign-in">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).pages.signIn, robots: { index: false } };
}

export default async function SignInPage() {
  const { t } = await getI18n();
  return (
    <Container className="flex justify-center py-12 sm:py-20">
      <Card className="w-full max-w-md">
        <h1 className="text-2xl font-bold">{t.auth.title}</h1>
        <p className="mt-1 mb-6 text-sm text-slate-600">{t.auth.subtitle}</p>
        <Suspense fallback={<Skeleton className="h-40 w-full" />}>
          <SignInForm />
        </Suspense>
      </Card>
    </Container>
  );
}
