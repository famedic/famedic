export default function JourneyEdge({ edge }) {
	const midX = (edge.from_x + edge.to_x) / 2;
	const midY = (edge.from_y + edge.to_y) / 2 - 12;
	const path = `M ${edge.from_x} ${edge.from_y} C ${midX} ${edge.from_y}, ${midX} ${edge.to_y}, ${edge.to_x} ${edge.to_y}`;

	return (
		<g>
			<path
				d={path}
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeDasharray={edge.dashed ? "6 6" : undefined}
				className="text-zinc-300 dark:text-zinc-600"
				markerEnd="url(#journey-arrow)"
			/>
			{edge.label ? (
				<text
					x={midX}
					y={midY}
					textAnchor="middle"
					className="fill-zinc-400 text-[10px] dark:fill-zinc-500"
				>
					{edge.label}
				</text>
			) : null}
		</g>
	);
}
