import { CheckCircleIcon } from "@heroicons/react/24/solid";

export default function OtpChannelCard({
  channel,
  title,
  description,
  maskedContact,
  footnote,
  selected,
  disabled,
  onSelect,
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      disabled={disabled}
      onClick={() => {
        if (!disabled) onSelect(channel);
      }}
      className={[
        "group relative w-full rounded-2xl border-2 p-5 text-left transition-all duration-200 sm:p-6",
        "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400",
        disabled
          ? "cursor-not-allowed border-slate-700/80 bg-slate-900/30 opacity-50"
          : selected
            ? "border-blue-400 bg-blue-500/15 shadow-lg shadow-blue-900/25 ring-1 ring-blue-400/40"
            : "border-slate-600/80 bg-slate-900/25 hover:border-slate-500 hover:bg-slate-800/40 hover:shadow-md",
        !disabled && selected ? "scale-[1.01]" : "",
      ].join(" ")}
    >
      <span
        className={[
          "absolute right-4 top-4 flex size-8 items-center justify-center rounded-full transition-all duration-300 ease-out",
          selected ? "scale-100 opacity-100" : "pointer-events-none scale-90 opacity-0",
        ].join(" ")}
        aria-hidden
      >
        <CheckCircleIcon className="size-7 text-blue-400 drop-shadow-sm" />
      </span>

      <span className="block pr-10 text-base font-semibold text-white">{title}</span>
      <span className="mt-1 block text-sm text-slate-400">{description}</span>

      {maskedContact ? (
        <span className="mt-3 block rounded-lg bg-slate-950/50 px-3 py-2 font-mono text-sm tracking-wide text-slate-200 ring-1 ring-slate-700/80">
          {maskedContact}
        </span>
      ) : null}

      {footnote ? <span className="mt-3 block text-xs leading-relaxed text-slate-500">{footnote}</span> : null}
    </button>
  );
}
