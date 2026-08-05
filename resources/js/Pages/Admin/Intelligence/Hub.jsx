import AdminLayout from "@/Layouts/AdminLayout";
import SuiteHeader from "@/Components/Admin/IntelligenceHub/SuiteHeader";
import SuiteCard from "@/Components/Admin/IntelligenceHub/SuiteCard";
import SuiteStats from "@/Components/Admin/IntelligenceHub/SuiteStats";
import ExecutiveSummary from "@/Components/Admin/IntelligenceHub/ExecutiveSummary";
import QuickActions from "@/Components/Admin/IntelligenceHub/QuickActions";
import HubSearch from "@/Components/Admin/IntelligenceHub/HubSearch";
import SectionTitle from "@/Components/Admin/IntelligenceHub/SectionTitle";

export default function Hub({
	suites = [],
	summary,
	quickActions = [],
	searchIndex = [],
	heroStats = {},
	meta = {},
}) {
	const heroItems = [
		{ label: "Suites", value: heroStats.suites ?? suites.length },
		{ label: "Módulos", value: heroStats.modules ?? 0 },
		{ label: "Alertas", value: heroStats.alerts ?? 0 },
		{ label: "Insights IA", value: heroStats.insights ?? 0 },
	];

	return (
		<AdminLayout title="Intelligence Hub">
			<div className="mx-auto max-w-7xl space-y-10">
				<SuiteHeader
					emoji="🧠"
					title={meta.title || "Intelligence Hub"}
					subtitle={meta.subtitle}
					badge="BETA"
					generatedAt={meta.generated_at}
					userName={meta.user_name}
				/>

				<section className="relative overflow-hidden rounded-[2rem] border border-zinc-200/70 bg-gradient-to-br from-zinc-50 via-white to-indigo-50/50 px-6 py-8 sm:px-8 dark:border-zinc-800 dark:from-zinc-950 dark:via-zinc-900 dark:to-indigo-950/30">
					<p className="text-sm font-medium text-zinc-500 dark:text-zinc-400">
						{meta.greeting || "Hola"}, {meta.user_name || "equipo"}
					</p>
					<h2 className="mt-2 max-w-3xl text-2xl font-semibold tracking-tight text-zinc-950 dark:text-white sm:text-3xl">
						Bienvenido al Intelligence Hub.
					</h2>
					<p className="mt-3 max-w-2xl text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
						Desde aquí podrás acceder a todos los módulos de inteligencia,
						analítica, IA y gobierno de la plataforma.
					</p>
					<div className="mt-6 max-w-xl">
						<SuiteStats
							stats={heroItems.map((item) => ({
								label: item.label,
								value: String(item.value),
							}))}
						/>
					</div>
				</section>

				<HubSearch items={searchIndex} />

				<ExecutiveSummary summary={summary} />

				<QuickActions actions={quickActions} />

				<section>
					<SectionTitle
						eyebrow="Catálogo"
						title="Suites disponibles"
						description="Cada suite es un producto independiente con sus propios dashboards y módulos."
					/>
					<div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
						{suites.map((suite) => (
							<SuiteCard key={suite.id} suite={suite} />
						))}
					</div>
				</section>
			</div>
		</AdminLayout>
	);
}
