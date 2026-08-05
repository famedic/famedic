import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { ArrowLeftIcon, ArrowRightIcon } from "@heroicons/react/16/solid";
import SuiteBadge from "@/Components/Admin/IntelligenceHub/SuiteBadge";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

export default function Show({ workspace, meta = {} }) {
	return (
		<AdminLayout title={`${workspace.name} · Workspace`}>
			<div className="mx-auto max-w-6xl space-y-8">
				<div className="flex flex-wrap gap-2">
					<Button href={workspace.home_url} outline>
						<ArrowLeftIcon className="size-4" />
						Workspace
					</Button>
				</div>

				<header className="space-y-3">
					<nav className="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
						<a href={workspace.home_url} className="hover:text-zinc-800 dark:hover:text-zinc-200">
							Workspace
						</a>
						<span className="text-zinc-300">/</span>
						<span className="font-medium text-zinc-700 dark:text-zinc-300">
							{workspace.name}
						</span>
					</nav>

					<div className="flex flex-wrap items-start justify-between gap-4">
						<div className="max-w-3xl">
							<div className="flex items-center gap-2">
								<span className="text-3xl" aria-hidden="true">
									{workspace.emoji}
								</span>
								{workspace.featured ? (
									<SuiteBadge variant="ai" className="ml-0">
										Prioritario
									</SuiteBadge>
								) : null}
							</div>
							<Heading className="mt-2 !text-3xl tracking-tight">
								{workspace.name}
							</Heading>
							<Text className="mt-2 text-base text-zinc-500 dark:text-zinc-400">
								{workspace.description}
							</Text>
						</div>
						<div className="text-right text-xs text-zinc-500">
							{meta.user_name ? <p>{meta.user_name}</p> : null}
							{meta.generated_at ? <p>{meta.generated_at}</p> : null}
						</div>
					</div>
				</header>

				<section className="space-y-3">
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Herramientas
					</p>
					<div className="divide-y divide-zinc-100 rounded-[1.5rem] border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900/50">
						{(workspace.tools || []).map((tool) => {
							const isSoon = tool.status === "coming_soon" || !tool.href;
							const row = (
								<div className="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40">
									<div className="min-w-0">
										<div className="flex flex-wrap items-center gap-2">
											<p className="font-semibold text-zinc-900 dark:text-zinc-50">
												{tool.title}
											</p>
											{isSoon ? (
												<SuiteBadge variant="comingSoon" className="ml-0">
													Soon
												</SuiteBadge>
											) : null}
										</div>
										<p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
											{tool.description}
										</p>
									</div>
									{!isSoon ? (
										<span className="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
											Abrir
											<ArrowRightIcon className="size-4" />
										</span>
									) : null}
								</div>
							);

							if (isSoon) {
								return <div key={tool.id}>{row}</div>;
							}

							return (
								<Link key={tool.id} href={tool.href} className="block">
									{row}
								</Link>
							);
						})}
					</div>
				</section>
			</div>
		</AdminLayout>
	);
}
