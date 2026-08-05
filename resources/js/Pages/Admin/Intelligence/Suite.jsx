import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { ArrowLeftIcon, ArrowRightIcon } from "@heroicons/react/16/solid";
import SuiteHeader from "@/Components/Admin/IntelligenceHub/SuiteHeader";
import SuiteStats from "@/Components/Admin/IntelligenceHub/SuiteStats";
import HubSidebar from "@/Components/Admin/IntelligenceHub/HubSidebar";
import SectionTitle from "@/Components/Admin/IntelligenceHub/SectionTitle";
import SuiteBadge from "@/Components/Admin/IntelligenceHub/SuiteBadge";

export default function Suite({ suite, meta = {} }) {
	return (
		<AdminLayout title={`${suite.name} · Intelligence Hub`}>
			<div className="mx-auto max-w-7xl space-y-8">
				<div className="flex flex-wrap gap-2">
					<Button href={suite.hub_url} outline>
						<ArrowLeftIcon className="size-4" />
						Intelligence Hub
					</Button>
				</div>

				<SuiteHeader
					emoji={suite.emoji}
					title={suite.name}
					subtitle={suite.description}
					badge="SUITE"
					badgeVariant="new"
					generatedAt={meta.generated_at}
					userName={meta.user_name}
					breadcrumbs={[
						{ label: "Intelligence Hub", href: suite.hub_url },
						{ label: suite.name },
					]}
				/>

				<SuiteStats stats={suite.stats || []} />

				<HubSidebar modules={suite.modules || []} />

				<section>
					<SectionTitle
						eyebrow="Módulos"
						title="Dashboard de la Suite"
						description="Navega con cards y el menú horizontal. El sidebar del admin permanece limpio."
					/>

					<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
						{(suite.modules || []).map((module) => {
							const isSoon = module.status === "coming_soon" || !module.href;
							const card = (
								<div
									className={`group flex h-full flex-col rounded-2xl border border-zinc-200/80 bg-white p-5 transition-all duration-300 dark:border-zinc-800 dark:bg-zinc-900/60 ${
										isSoon
											? "opacity-75"
											: "hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-lg dark:hover:border-zinc-600"
									}`}
								>
									<div className="flex items-start justify-between gap-2">
										<h3 className="text-base font-semibold text-zinc-900 dark:text-white">
											{module.title}
										</h3>
										{isSoon ? (
											<SuiteBadge variant="comingSoon" className="ml-0" />
										) : null}
									</div>
									<p className="mt-2 flex-1 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
										{module.description}
									</p>
									<div className="mt-4 flex items-center justify-between border-t border-zinc-100 pt-3 dark:border-zinc-800">
										<span className="text-[11px] uppercase tracking-wide text-zinc-400">
											{isSoon ? "Roadmap" : "Módulo"}
										</span>
										{isSoon ? (
											<span className="text-sm text-zinc-400">Próximamente</span>
										) : (
											<span className="inline-flex items-center gap-1 text-sm font-semibold text-zinc-800 dark:text-zinc-100">
												Abrir
												<ArrowRightIcon className="size-3.5 transition-transform group-hover:translate-x-0.5" />
											</span>
										)}
									</div>
								</div>
							);

							if (isSoon) {
								return <div key={module.id}>{card}</div>;
							}

							return (
								<Link key={module.id} href={module.href} className="block h-full">
									{card}
								</Link>
							);
						})}
					</div>
				</section>
			</div>
		</AdminLayout>
	);
}
