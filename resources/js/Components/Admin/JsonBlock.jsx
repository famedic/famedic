import { Text } from "@/Components/Catalyst/text";

export default function JsonBlock({ title, data, emptyLabel = "Sin datos." }) {
	const hasData = data !== null && data !== undefined && data !== "";

	return (
		<div className="space-y-2">
			{title && (
				<Text className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
					{title}
				</Text>
			)}
			{hasData ? (
				<pre className="max-h-[28rem] overflow-auto rounded-lg bg-zinc-900 p-4 text-xs text-zinc-100">
					{typeof data === "string" ? data : JSON.stringify(data, null, 2)}
				</pre>
			) : (
				<Text className="text-sm text-zinc-500">{emptyLabel}</Text>
			)}
		</div>
	);
}
