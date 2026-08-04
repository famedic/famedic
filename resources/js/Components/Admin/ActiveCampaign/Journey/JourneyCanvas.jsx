import JourneyNode from "./JourneyNode";
import JourneyEdge from "./JourneyEdge";
import { Text } from "@/Components/Catalyst/text";

export default function JourneyCanvas({
	journey,
	selectedNodeId = null,
	onSelectNode,
}) {
	if (!journey?.nodes?.length) {
		return (
			<div className="flex h-64 items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-white dark:border-zinc-700 dark:bg-zinc-900">
				<Text className="text-sm text-zinc-500">
					No hay nodos para mostrar con los filtros actuales.
				</Text>
			</div>
		);
	}

	const width = journey.canvas?.width || 960;
	const height = journey.canvas?.height || 280;

	return (
		<div className="overflow-x-auto rounded-xl border border-zinc-200 bg-gradient-to-b from-zinc-50 to-white shadow-sm dark:border-zinc-700 dark:from-zinc-950 dark:to-zinc-900">
			<div className="relative" style={{ width, height: height + 40 }}>
				<svg
					width={width}
					height={height + 40}
					className="absolute inset-0"
					aria-hidden="true"
				>
					<defs>
						<marker
							id="journey-arrow"
							markerWidth="8"
							markerHeight="8"
							refX="6"
							refY="3"
							orient="auto"
						>
							<path
								d="M0,0 L6,3 L0,6 Z"
								className="fill-zinc-300 dark:fill-zinc-600"
							/>
						</marker>
					</defs>
					{(journey.edges || []).map((edge) => (
						<JourneyEdge key={edge.id} edge={edge} />
					))}
				</svg>

				{(journey.nodes || []).map((node) => (
					<JourneyNode
						key={node.id}
						node={node}
						selected={selectedNodeId === node.id}
						onSelect={onSelectNode}
					/>
				))}
			</div>
		</div>
	);
}
