import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Sesión de confianza OTP para un pedido de laboratorio (sincronizada con GET otp.status).
 *
 * @param {number|string} purchaseId
 * @param {{ onExpired?: () => void }} [options]
 */
export function useLabResultsOtpSession(purchaseId, options = {}) {
  const { onExpired } = options;
  const [secondsLeft, setSecondsLeft] = useState(0);
  const onExpiredRef = useRef(onExpired);
  onExpiredRef.current = onExpired;

  const verified = secondsLeft > 0;

  const refresh = useCallback(async () => {
    if (purchaseId == null || purchaseId === "") {
      setSecondsLeft(0);
      return;
    }
    try {
      const url = route("otp.status", { laboratory_purchase: purchaseId });
      const res = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data?.verified && data.expires_in > 0) {
        setSecondsLeft(Math.floor(Number(data.expires_in)));
      } else {
        setSecondsLeft(0);
      }
    } catch {
      setSecondsLeft(0);
    }
  }, [purchaseId]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const armSession = useCallback((expiresIn) => {
    const n = Number(expiresIn);
    if (Number.isFinite(n) && n > 0) {
      setSecondsLeft(Math.floor(n));
    }
  }, []);

  const isActive = secondsLeft > 0;

  useEffect(() => {
    if (!isActive) return undefined;
    const id = setInterval(() => {
      setSecondsLeft((s) => {
        if (s <= 1) {
          queueMicrotask(() => onExpiredRef.current?.());
          return 0;
        }
        return s - 1;
      });
    }, 1000);
    return () => clearInterval(id);
  }, [isActive]);

  return {
    verified,
    secondsLeft,
    refresh,
    armSession,
  };
}
