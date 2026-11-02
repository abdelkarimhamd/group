"use client";

import { useSyncExternalStore } from "react";

// One shared ticking clock for every countdown on the page. The server
// snapshot is null, so server-rendered HTML shows absolute dates and the
// browser switches to "closes in…" after hydration without a mismatch.

let now = 0;
let timer: ReturnType<typeof setInterval> | undefined;
const listeners = new Set<() => void>();

function subscribe(listener: () => void) {
  listeners.add(listener);
  if (!timer) {
    now = Date.now();
    timer = setInterval(() => {
      now = Date.now();
      listeners.forEach((notify) => notify());
    }, 1000);
  }
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0 && timer) {
      clearInterval(timer);
      timer = undefined;
    }
  };
}

function getSnapshot(): number | null {
  if (!now) now = Date.now();
  return now;
}

function getServerSnapshot(): number | null {
  return null;
}

/** Milliseconds since the epoch, updated every second; null while server rendering. */
export function useNow(): number | null {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
