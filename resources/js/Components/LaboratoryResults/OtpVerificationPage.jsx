import LabResultsVerificationLayout from "@/Components/LaboratoryResults/LabResultsVerificationLayout";
import OtpChannelCard from "@/Components/LaboratoryResults/OtpChannelCard";
import SecurityNotice from "@/Components/LaboratoryResults/SecurityNotice";

export default function OtpVerificationPage({
  patientDisplayName,
  orderNumber,
  studyDateLabel,
  maskedPhone,
  maskedEmail,
  canUseSms,
  canUseEmail,
  selectedChannel,
  onChannelChange,
  onSubmit,
  submitting,
  channelSelected,
  otpExpiryMinutes,
}) {
  return (
    <LabResultsVerificationLayout
      patientDisplayName={patientDisplayName}
      orderNumber={orderNumber}
      studyDateLabel={studyDateLabel}
    >
      <div className="flex min-h-0 flex-col">
        <header className="mb-6">
          <h3 className="text-center text-xl font-bold text-white sm:text-left sm:text-2xl">Recibe tu código de seguridad</h3>
          <p className="mt-2 text-center text-sm leading-relaxed text-slate-400 sm:text-left sm:text-base">
            Selecciona el canal donde deseas recibir tu código OTP.
          </p>
        </header>

        <form className="flex flex-1 flex-col space-y-5" onSubmit={onSubmit}>
          <fieldset
            className="min-w-0 space-y-4"
            role="radiogroup"
            aria-label="Canal de envío del código"
          >
            <legend className="sr-only">Canal de envío del código</legend>

            <div className="grid gap-4 sm:grid-cols-1">
              <OtpChannelCard
                channel="sms"
                title="Mensaje (SMS)"
                description={null}
                maskedContact={maskedPhone}
                footnote={null}
                selected={selectedChannel === "sms"}
                disabled={!canUseSms}
                onSelect={onChannelChange}
              />

              <OtpChannelCard
                channel="email"
                title="Correo electrónico"
                description={null}
                maskedContact={maskedEmail}
                footnote={null}
                selected={selectedChannel === "email"}
                disabled={!canUseEmail}
                onSelect={onChannelChange}
              />
            </div>
          </fieldset>

          <SecurityNotice otpExpiryMinutes={otpExpiryMinutes} />

          <div className="pt-2">
            <button
              type="submit"
              disabled={submitting || !channelSelected}
              className="flex w-full min-h-[3.25rem] items-center justify-center rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 px-6 py-4 text-base font-semibold text-white shadow-lg shadow-blue-900/40 transition hover:from-blue-500 hover:to-blue-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-300 disabled:cursor-not-allowed disabled:from-slate-600 disabled:to-slate-600 disabled:shadow-none"
            >
              {submitting ? (
                <span className="inline-flex items-center gap-2">
                  <span className="inline-block size-5 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                  Enviando código…
                </span>
              ) : (
                "Enviar código"
              )}
            </button>
          </div>

          <p className="text-center text-xs text-slate-500">
            ¿No recibiste el código? Tras enviarlo podrás reenviarlo o cambiar de canal desde el siguiente paso.
          </p>
        </form>
      </div>
    </LabResultsVerificationLayout>
  );
}
