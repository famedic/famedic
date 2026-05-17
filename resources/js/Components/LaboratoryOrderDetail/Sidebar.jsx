import Card from "@/Components/Card";

export default function Sidebar({ title, children }) {
	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-5">
			{title && (
				<h3 className="mb-4 break-words text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-slate-400">
					{title}
				</h3>
			)}
			<div className="min-w-0 space-y-4">{children}</div>
		</Card>
	);
}
