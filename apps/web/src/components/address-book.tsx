"use client";

import { useState, useTransition } from "react";
import { deleteAddress, saveAddress } from "@/actions/account";
import { useI18n } from "@/i18n/client";
import type { Address, AddressInput } from "@/lib/api/types";
import { AddressLines } from "./account-bits";
import { AddressForm } from "./address-form";
import { Alert, Badge, Button, Card, EmptyState, Spinner } from "./ui";

type Editing = { kind: "none" } | { kind: "new" } | { kind: "edit"; id: string };

function inputOf(address: Address): AddressInput {
  const { id: _id, ...input } = address;
  return input;
}

/** The customer's addresses. Every change refreshes the list from the API. */
export function AddressBook({ addresses, defaults }: { addresses: Address[]; defaults: { name: string | null; phone: string } }) {
  const { t, locale } = useI18n();
  const [editing, setEditing] = useState<Editing>({ kind: "none" });
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [, startTransition] = useTransition();

  function remove(id: string) {
    setNotice(null);
    setBusy(id);
    startTransition(async () => {
      const result = await deleteAddress({ locale, id });
      setBusy(null);
      setConfirmDelete(null);
      setNotice(
        result.ok
          ? { tone: "success", text: t.addresses.deleted }
          : { tone: "danger", text: result.status === 0 ? t.common.networkError : t.common.genericError },
      );
    });
  }

  function makeDefault(address: Address) {
    setNotice(null);
    setBusy(address.id);
    startTransition(async () => {
      // PATCH replaces the whole address, so every field is sent back.
      const result = await saveAddress({ locale, id: address.id, address: { ...inputOf(address), is_default: true } });
      setBusy(null);
      if (!result.ok) {
        setNotice({ tone: "danger", text: result.status === 0 ? t.common.networkError : t.common.genericError });
      }
    });
  }

  const saved = () => {
    setEditing({ kind: "none" });
    setNotice({ tone: "success", text: t.addresses.saved });
  };

  return (
    <div className="space-y-4">
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}

      {editing.kind === "new" ? (
        <Card>
          <h2 className="mb-4 text-lg font-bold">{t.addresses.addTitle}</h2>
          <AddressForm
            defaults={defaults}
            makeDefaultInitially={addresses.length === 0}
            onSaved={saved}
            onCancel={() => setEditing({ kind: "none" })}
          />
        </Card>
      ) : (
        <Button onClick={() => setEditing({ kind: "new" })} data-testid="add-address">
          + {t.addresses.add}
        </Button>
      )}

      {addresses.length === 0 && editing.kind !== "new" ? <EmptyState>{t.addresses.empty}</EmptyState> : null}

      <ul className="grid gap-4 md:grid-cols-2">
        {addresses.map((address) => (
          <li key={address.id}>
            <Card className="h-full space-y-4" data-testid="address-card">
              {editing.kind === "edit" && editing.id === address.id ? (
                <>
                  <h2 className="text-lg font-bold">{t.addresses.editTitle}</h2>
                  <AddressForm address={address} onSaved={saved} onCancel={() => setEditing({ kind: "none" })} />
                </>
              ) : (
                <>
                  <div className="flex items-start justify-between gap-3">
                    <AddressLines address={address} />
                    {address.is_default ? <Badge tone="brand">{t.addresses.default}</Badge> : null}
                  </div>
                  {confirmDelete === address.id ? (
                    <div className="space-y-3 rounded-xl bg-rose-50 p-3">
                      <p className="text-sm text-rose-900">{t.addresses.deleteConfirm}</p>
                      <div className="flex gap-2">
                        <Button variant="danger" onClick={() => remove(address.id)} disabled={busy === address.id}>
                          {busy === address.id ? <Spinner /> : null}
                          {t.common.delete}
                        </Button>
                        <Button variant="ghost" onClick={() => setConfirmDelete(null)}>
                          {t.common.cancel}
                        </Button>
                      </div>
                    </div>
                  ) : (
                    <div className="flex flex-wrap gap-2">
                      <Button variant="secondary" onClick={() => setEditing({ kind: "edit", id: address.id })}>
                        {t.common.edit}
                      </Button>
                      {!address.is_default ? (
                        <Button variant="ghost" onClick={() => makeDefault(address)} disabled={busy === address.id}>
                          {busy === address.id ? <Spinner /> : null}
                          {t.addresses.makeDefault}
                        </Button>
                      ) : null}
                      <Button variant="ghost" onClick={() => setConfirmDelete(address.id)} className="text-rose-700">
                        {t.common.delete}
                      </Button>
                    </div>
                  )}
                </>
              )}
            </Card>
          </li>
        ))}
      </ul>
    </div>
  );
}
