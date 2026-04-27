import { ShieldCheckIcon } from "@heroicons/react/24/outline";

export default function SecurityNotice({ otpExpiryMinutes = 10, className = "" }) {
  const minutes = Number.isFinite(Number(otpExpiryMinutes)) ? Math.max(1, Math.round(Number(otpExpiryMinutes))) : 10;

  return (
    <div
      className={`rounded-2xl border border-cyan-500/25 bg-gradient-to-br from-cyan-950/40 to-slate-900/60 p-4 sm:p-5 ${className}`}
      role="status"
    >
      <div className="flex gap-3">
        <ShieldCheckIcon className="size-8 shrink-0 text-cyan-400/90" aria-hidden />
        <div className="min-w-0 space-y-2 text-sm text-cyan-50/95">
          <p className="font-semibold text-cyan-100">Seguridad</p>
          <ul className="list-inside list-disc space-y-1.5 text-cyan-50/85 marker:text-cyan-400/80">
            <li>El código OTP es personal e intransferible.</li>
            <li>Válido por {minutes} minutos.</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
