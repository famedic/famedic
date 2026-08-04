import { Text } from "@/Components/Catalyst/text";
import { SparklesIcon } from "@heroicons/react/24/outline";
import { EMPTY_STATE_SUGGESTIONS } from "../prompts";

export default function EmptyState({ onSelect, disabled }) {
	return (
		<div className="flex flex-1 flex-col items-center justify-center px-4 py-10 text-center sm:px-6">
			<div className="mb-5 flex size-16 items-center justify-center rounded-3xl bg-gradient-to-br from-famedic-dark to-famedic-light text-white shadow-md">
				<SparklesIcon className="size-8" />
			</div>
			<h2 className="font-poppins text-xl font-semibold text-zinc-950 dark:text-white">
				¿Qué quieres revisar hoy?
			</h2>
			<Text className="mt-2 max-w-lg text-sm text-zinc-600 dark:text-zinc-400">
				Puedo ayudarte a resumir actividad operativa, detectar fallas y revisar
				usuarios, compras, carritos o Murguía.
			</Text>
			<div className="mt-8 grid w-full max-w-2xl gap-3 sm:grid-cols-2">
				{EMPTY_STATE_SUGGESTIONS.map((suggestion) => (
					<button
						key={suggestion.label}
						type="button"
						disabled={disabled}
						onClick={() => onSelect(suggestion.question)}
						className="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left text-sm text-zinc-700 shadow-sm transition hover:border-famedic-light/40 hover:bg-famedic-light/5 hover:text-famedic-dark disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-famedic-light/30 dark:hover:bg-famedic-light/10"
					>
						{suggestion.label}
					</button>
				))}
			</div>
		</div>
	);
}
