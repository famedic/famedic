import { Link } from "@inertiajs/react";
import { ArrowRightIcon } from "@heroicons/react/16/solid";
import SuiteBadge from "@/Components/Admin/IntelligenceHub/SuiteBadge";

export default function WorkspaceSection({ workspace }) {
	const featured = Boolean(workspace.featured);
	const gateway = workspace.home_mode === "gateway";
	const tools = workspace.tools || [];
	const highlights = workspace.highlights || [];

	return (
		<section
			className={`rounded-[1.75rem] border border-zinc-200/80 bg-white dark:border-zinc-800 dark:bg-zinc-900/50 ${
				featured
					? "border-teal-200/70 bg-gradient-to-br from-teal-50/40 via-white to-white p-7 sm:p-9 dark:border-teal-500/20 dark:from-teal-950/20 dark:via-zinc-900 dark:to-zinc-900"
					: gateway
						? "border-sky-200/70 bg-gradient-to-br from-sky-50/50 via-white to-white p-7 sm:p-8 dark:border-sky-500/20 dark:from-sky-950/20 dark:via-zinc-900 dark:to-zinc-900"
						: "p-6 sm:p-7"
			}`}
		>
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div className="max-w-3xl">
					<div className="flex flex-wrap items-center gap-2">
						<span className={featured || gateway ? "text-3xl" : "text-2xl"} aria-hidden="true">
							{workspace.emoji}
						</span>
						{featured ? (
							<SuiteBadge variant="ai" className="ml-0">
								Prioritario
							</SuiteBadge>
						) : null}
						{gateway ? (
							<SuiteBadge variant="new" className="ml-0">
								{workspace.badge || "CRM"}
							</SuiteBadge>
						) : null}
					</div>
					<h2
						className={`mt-3 font-semibold tracking-tight text-zinc-950 dark:text-white ${
							featured || gateway ? "text-2xl sm:text-3xl" : "text-xl"
						}`}
					>
						{workspace.name}
					</h2>
					<p
						className={`mt-2 text-zinc-500 dark:text-zinc-400 ${
							featured || gateway ? "text-base" : "text-sm"
						}`}
					>
						{workspace.description}
					</p>
				</div>

				<Link
					href={workspace.href}
					className="inline-flex items-center gap-2 rounded-xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
				>
					{workspace.cta || "Abrir"}
					<ArrowRightIcon className="size-4" />
				</Link>
			</div>

			{gateway ? (
				<div className="mt-6 flex flex-wrap gap-2">
					{highlights.map((item) => (
						<span
							key={item}
							className="rounded-full border border-sky-200/80 bg-white/80 px-3.5 py-1.5 text-sm font-medium text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100"
						>
							{item}
						</span>
					))}
				</div>
			) : (
				<div
					className={`mt-6 grid gap-2.5 ${
						featured
							? "sm:grid-cols-2 lg:grid-cols-3"
							: "sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
					}`}
				>
					{tools.map((tool) => {
						const isSoon = tool.status === "coming_soon" || !tool.href;
						const chip = (
							<div
								className={`rounded-xl border px-3.5 py-3 transition ${
									isSoon
										? "border-dashed border-zinc-200 bg-zinc-50/80 opacity-70 dark:border-zinc-700 dark:bg-zinc-800/40"
										: "border-zinc-200 bg-zinc-50/90 hover:border-zinc-300 hover:bg-white hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50 dark:hover:border-zinc-600 dark:hover:bg-zinc-800"
								} ${featured && !isSoon ? "min-h-[5.5rem]" : ""}`}
							>
								<div className="flex items-start justify-between gap-2">
									<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
										{tool.title}
									</p>
									{isSoon ? (
										<SuiteBadge variant="comingSoon" className="ml-0 shrink-0">
											Soon
										</SuiteBadge>
									) : null}
								</div>
								{featured || !isSoon ? (
									<p className="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
										{tool.description}
									</p>
								) : null}
							</div>
						);

						if (isSoon) {
							return <div key={tool.id}>{chip}</div>;
						}

						return (
							<Link key={tool.id} href={tool.href} className="block">
								{chip}
							</Link>
						);
					})}
				</div>
			)}
		</section>
	);
}
