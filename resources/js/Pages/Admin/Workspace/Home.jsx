import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Link } from "@inertiajs/react";
import SuiteBadge from "@/Components/Admin/IntelligenceHub/SuiteBadge";
import WorkspaceSearch from "@/Components/Admin/Workspace/WorkspaceSearch";
import WorkspaceSection from "@/Components/Admin/Workspace/WorkspaceSection";
import SmartRecommendations from "@/Components/Admin/Workspace/SmartRecommendations";

export default function Home({
	workspaces = [],
	recommendations,
	quickActions = [],
	searchIndex = [],
	meta = {},
}) {
	return (
		<AdminLayout title="Workspace">
			<div className="mx-auto max-w-6xl space-y-10">
				<header className="space-y-4">
					<div className="flex flex-wrap items-start justify-between gap-4">
						<div>
							<div className="flex items-center gap-2">
								<span className="text-2xl" aria-hidden="true">
									🧠
								</span>
								<SuiteBadge variant="beta" className="ml-0">
									BETA
								</SuiteBadge>
							</div>
							<Heading className="mt-2 !text-3xl tracking-tight">
								{meta.title || "Workspace"}
							</Heading>
							<Text className="mt-2 max-w-2xl text-base text-zinc-500 dark:text-zinc-400">
								{meta.subtitle ||
									"Centro de trabajo inteligente para operar, analizar y hacer crecer Famedic."}
							</Text>
						</div>
						<div className="space-y-1 text-right text-xs text-zinc-500">
							{meta.user_name ? <p>{meta.user_name}</p> : null}
							{meta.generated_at ? <p>{meta.generated_at}</p> : null}
						</div>
					</div>

					{quickActions.length > 0 ? (
						<div className="flex flex-wrap gap-2">
							{quickActions.map((action) => (
								<Link
									key={action.id}
									href={action.href}
									className="rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
								>
									{action.label}
								</Link>
							))}
						</div>
					) : null}
				</header>

				<section className="space-y-5 rounded-[2rem] border border-zinc-200/70 bg-gradient-to-br from-zinc-50 via-white to-zinc-50/30 px-6 py-8 sm:px-8 dark:border-zinc-800 dark:from-zinc-950 dark:via-zinc-900 dark:to-zinc-950">
					<div>
						<p className="text-sm text-zinc-500 dark:text-zinc-400">
							{meta.greeting || "Hola"}, {meta.user_name || "equipo"}
						</p>
						<h2 className="mt-2 text-2xl font-semibold tracking-tight text-zinc-950 dark:text-white sm:text-3xl">
							¿Qué deseas hacer hoy?
						</h2>
					</div>
					<WorkspaceSearch items={searchIndex} large />
				</section>

				<div className="space-y-6">
					{workspaces.map((workspace) => (
						<WorkspaceSection key={workspace.id} workspace={workspace} />
					))}
				</div>

				<SmartRecommendations recommendations={recommendations} />
			</div>
		</AdminLayout>
	);
}
