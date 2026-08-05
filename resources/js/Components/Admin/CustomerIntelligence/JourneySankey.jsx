import { useMemo } from "react";
import {
	ResponsiveContainer,
	Sankey,
	Tooltip,
} from "recharts";
import {
	ChartCard,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneySankey({ sankey }) {
	const data = useMemo(() => {
		const nodes = sankey?.nodes || [];
		const links = (sankey?.links || [])
			.map((link) => {
				const source = nodes.findIndex((n) => n.name === link.source);
				const target = nodes.findIndex((n) => n.name === link.target);
				if (source < 0 || target < 0) return null;
				return { source, target, value: link.value };
			})
			.filter(Boolean);

		return { nodes, links };
	}, [sankey]);

	const hasData = data.nodes.length > 1 && data.links.length > 0;

	return (
		<ChartCard
			title="Sankey · flujo de usuarios"
			description="Rutas entre etapas. El nodo Abandono concentra caídas."
		>
			<div className="h-80 w-full">
				{hasData ? (
					<ResponsiveContainer width="100%" height="100%">
						<Sankey
							data={data}
							nodePadding={24}
							nodeWidth={12}
							linkCurvature={0.5}
							iterations={32}
							margin={{ top: 8, right: 160, bottom: 8, left: 16 }}
						>
							<Tooltip
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
						</Sankey>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin suficiente flujo para el diagrama
					</div>
				)}
			</div>
			{hasData ? (
				<div className="mt-2 flex flex-wrap gap-2 text-[11px] text-zinc-500">
					<span
						className="inline-flex items-center gap-1.5"
					>
						<span
							className="size-2 rounded-full"
							style={{ background: DASHBOARD_COLORS.blue }}
						/>
						Flujo activo
					</span>
					<span className="inline-flex items-center gap-1.5">
						<span
							className="size-2 rounded-full"
							style={{ background: DASHBOARD_COLORS.red }}
						/>
						Abandono
					</span>
				</div>
			) : null}
		</ChartCard>
	);
}
