import { useEffect, useRef } from "react";
import {
	Chart,
	LineController,
	BarController,
	DoughnutController,
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	BarElement,
	ArcElement,
	Tooltip,
	Legend,
	Filler,
} from "chart.js";

Chart.register(
	LineController,
	BarController,
	DoughnutController,
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	BarElement,
	ArcElement,
	Tooltip,
	Legend,
	Filler,
);

export default function OpsChart({
	type = "line",
	labels = [],
	values = [],
	label = "Serie",
	color = "#0284c7",
	height = 180,
}) {
	const canvasRef = useRef(null);
	const chartRef = useRef(null);

	useEffect(() => {
		if (!canvasRef.current) {
			return undefined;
		}

		chartRef.current?.destroy();

		const isDoughnut = type === "doughnut" || type === "pie";
		const palette = ["#059669", "#0284c7", "#d97706", "#e11d48", "#6366f1", "#0d9488"];

		chartRef.current = new Chart(canvasRef.current, {
			type,
			data: {
				labels,
				datasets: [
					{
						label,
						data: values,
						borderColor: isDoughnut ? palette : color,
						backgroundColor: isDoughnut
							? palette
							: type === "bar"
								? color
								: `${color}22`,
						fill: type === "line",
						tension: 0.35,
						borderWidth: 2,
						pointRadius: labels.length > 40 ? 0 : 2,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: isDoughnut },
					tooltip: { mode: "index", intersect: false },
				},
				scales: isDoughnut
					? {}
					: {
							x: {
								grid: { display: false },
								ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
							},
							y: {
								beginAtZero: true,
								grid: { color: "rgba(113,113,122,0.12)" },
								ticks: { precision: 0 },
							},
						},
			},
		});

		return () => {
			chartRef.current?.destroy();
			chartRef.current = null;
		};
	}, [type, labels, values, label, color]);

	if (!labels?.length) {
		return (
			<div
				className="flex items-center justify-center text-xs text-zinc-400"
				style={{ height }}
			>
				Sin datos en el periodo
			</div>
		);
	}

	return (
		<div style={{ height }}>
			<canvas ref={canvasRef} />
		</div>
	);
}
