import { Text } from "@/Components/Catalyst/text";

export default function MarketingCampaignFormSection({
	title,
	description,
	defaultOpen = false,
	children,
}) {
	return (
		<details
			className="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
			open={defaultOpen}
		>
			<summary className="cursor-pointer list-none px-4 py-3 sm:px-5 sm:py-4">
				<div className="flex items-start justify-between gap-3">
					<div>
						<Text className="font-medium">{title}</Text>
						{description ? (
							<Text className="mt-1 text-sm text-zinc-500">
								{description}
							</Text>
						) : null}
					</div>
					<span className="text-sm text-zinc-400">Mostrar / ocultar</span>
				</div>
			</summary>
			<div className="space-y-4 border-t border-zinc-100 px-4 py-4 dark:border-zinc-800 sm:px-5">
				{children}
			</div>
		</details>
	);
}
