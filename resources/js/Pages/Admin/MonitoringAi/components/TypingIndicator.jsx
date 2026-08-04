import { SparklesIcon } from "@heroicons/react/24/outline";

export default function TypingIndicator() {
	return (
		<div className="flex items-end gap-3 px-4 py-3 sm:px-6">
			<div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-famedic-dark to-famedic-light text-white">
				<SparklesIcon className="size-4" />
			</div>
			<div className="rounded-2xl rounded-bl-md border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<p className="mb-1 text-xs font-medium text-zinc-500">Asistente IA</p>
				<div className="flex items-center gap-1.5">
					<span className="size-2 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.3s]" />
					<span className="size-2 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.15s]" />
					<span className="size-2 animate-bounce rounded-full bg-zinc-400" />
					<span className="ml-2 text-sm text-zinc-500">Analizando datos…</span>
				</div>
			</div>
		</div>
	);
}
