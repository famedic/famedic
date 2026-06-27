import StudyInfoCard from "@/Components/LaboratoryResults/StudyInfoCard";

export default function LabResultsVerificationLayout({
  patientDisplayName,
  orderNumber,
  studyDateLabel,
  children,
}) {
  return (
    <div className="overflow-hidden rounded-[1.35rem] border border-slate-600/50 bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950/90 p-6 shadow-2xl shadow-blue-950/30 ring-1 ring-white/5 sm:p-8 lg:p-10">
      <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.08fr)] lg:items-start lg:gap-12">
        <StudyInfoCard
          patientDisplayName={patientDisplayName}
          orderNumber={orderNumber}
          studyDateLabel={studyDateLabel}
        />
        <div className="min-w-0">{children}</div>
      </div>
    </div>
  );
}
