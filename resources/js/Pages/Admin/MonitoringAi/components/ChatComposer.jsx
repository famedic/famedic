import { useRef } from "react";
import { PaperAirplaneIcon } from "@heroicons/react/24/solid";

export default function ChatComposer({
	value,
	onChange,
	onSubmit,
	loading,
	disabled,
	maxLength = 2000,
}) {
	const textareaRef = useRef(null);

	const handleKeyDown = (e) => {
		if (e.key === "Enter" && !e.shiftKey) {
			e.preventDefault();
			if (!loading && value.trim() && !disabled) {
				onSubmit();
			}
		}
	};

	const handleSubmit = (e) => {
		e.preventDefault();
		if (!loading && value.trim() && !disabled) {
			onSubmit();
		}
	};

	return (
		<div className="shrink-0 border-t border-zinc-200/80 bg-white/90 px-4 py-3 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-950/90 sm:px-6 sm:py-4">
			<form onSubmit={handleSubmit}>
				<div className="relative flex items-end gap-2 rounded-2xl border border-zinc-200 bg-zinc-50/80 p-2 shadow-sm focus-within:border-famedic-light/50 focus-within:ring-2 focus-within:ring-famedic-light/20 dark:border-zinc-700 dark:bg-zinc-900/80 dark:focus-within:border-famedic-light/40">
					<textarea
						ref={textareaRef}
						rows={1}
						value={value}
						onChange={(e) => onChange(e.target.value)}
						onKeyDown={handleKeyDown}
						placeholder="Pregunta sobre usuarios, compras, carritos, fallas o actividad del día…"
						disabled={disabled || loading}
						maxLength={maxLength}
						className="max-h-40 min-h-[44px] flex-1 resize-none bg-transparent px-2 py-2.5 text-sm leading-relaxed text-zinc-950 placeholder:text-zinc-400 focus:outline-none disabled:opacity-50 dark:text-white dark:placeholder:text-zinc-500"
					/>
					<button
						type="submit"
						disabled={disabled || loading || !value.trim()}
						aria-label="Enviar pregunta"
						className="mb-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-famedic-dark text-white shadow-sm transition hover:bg-famedic-darker disabled:cursor-not-allowed disabled:opacity-40 dark:bg-famedic-light dark:text-famedic-dark dark:hover:bg-famedic-light/90"
					>
						{loading ? (
							<span className="size-4 animate-spin rounded-full border-2 border-white/30 border-t-white dark:border-famedic-dark/30 dark:border-t-famedic-dark" />
						) : (
							<PaperAirplaneIcon className="size-4" />
						)}
					</button>
				</div>
				<div className="mt-1.5 flex items-center justify-between px-1">
					<p className="text-[11px] text-zinc-400">
						Enter para enviar · Shift + Enter para nueva línea
					</p>
					<p className="text-[11px] text-zinc-400">
						{value.length}/{maxLength}
					</p>
				</div>
			</form>
		</div>
	);
}
