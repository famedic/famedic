import formatMmSs from "@/utils/formatMmSs";

/**
 * Indicador discreto de sesión de verificación activa (ventana de confianza).
 * @param {{ secondsLeft: number }} props
 */
export default function LabResultsOtpSessionBadge({ secondsLeft }) {
  if (!secondsLeft || secondsLeft <= 0) return null;

  return (
    <div
      className="pointer-events-none fixed bottom-4 right-4 z-40 max-w-[min(100vw-2rem,20rem)] rounded-lg border border-emerald-200/80 bg-emerald-50/95 px-3 py-2 text-xs text-emerald-900 shadow-md backdrop-blur-sm dark:border-emerald-800/80 dark:bg-emerald-950/90 dark:text-emerald-100"
      role="status"
      aria-live="polite"
    >
      <span className="font-medium">Sesión segura activa:</span>{" "}
      <span className="tabular-nums">{formatMmSs(secondsLeft)}</span> restantes
    </div>
  );
}
